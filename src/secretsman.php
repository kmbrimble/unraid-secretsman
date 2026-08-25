<?php
declare(strict_types=1);

/**
 * secretsman — version-independent secret resolver.
 *
 * Single entry point secretsman_resolve() is called by the Phase 2 patch
 * layer in one line, from the narrowest insertion point in Helpers.php's
 * xmlToCommand(): immediately before the `foreach ($xml['Config'] ...)`
 * loop, while values are still unescaped. It mutates $xml['Config'] and
 * $xml['ExtraParams'] in place; the existing stock loop then does all the
 * emitting, so no caller needs touching and the resolved secret never
 * enters the returned $cmd string.
 *
 * FAIL CLOSED. Every error path throws SecretsmanError and never includes
 * a resolved secret value in its message.
 */

final class SecretsmanError extends \RuntimeException
{
}

/**
 * Classify a single field value.
 *
 *   - 'none'    not secrets syntax at all; caller leaves the value alone.
 *   - 'literal' escaped ("\!secret ..."); caller uses ['value'], never
 *               resolved.
 *   - 'token'   a well-formed "!secret ns/key". ("!secretfile ns/key" is
 *               recognised too, only so it can throw a clear removal
 *               error below — it is no longer a live mode.)
 *
 * Anything that merely *looks* like an attempt at the syntax (contains
 * "!secret" but isn't exactly one of the two valid whole-field forms —
 * wrong case, embedded in other text, missing/extra slash, bad charset,
 * ...) throws rather than silently passing the literal "!secret ..." text
 * through to a container. That is the fail-closed guarantee: a malformed
 * token can never reach a variable's value.
 */
function secretsman_parse_token(string $rawValue): array
{
    $value = trim($rawValue);

    if ($value === '') {
        return ['kind' => 'none'];
    }

    if (str_starts_with($value, '\\!secret')) {
        return ['kind' => 'literal', 'value' => substr($value, 1)];
    }

    if (stripos($value, '!secret') === false) {
        return ['kind' => 'none'];
    }

    if (!preg_match('/^!(secretfile|secret)\s+([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/', $value, $m)) {
        throw new SecretsmanError(
            "secretsman: malformed or misplaced secret token — a field value must be " .
            "exactly '!secret ns/key' with no other text"
        );
    }

    if ($m[1] === 'secretfile') {
        throw new SecretsmanError(
            "secretsman: !secretfile was removed (see CLAUDE.md) — its only benefit over " .
            "!secret was hiding a value from docker inspect/proc-environ, which already " .
            "requires host access the store is equally readable with. Use '!secret " .
            "{$m[2]}/{$m[3]}' instead."
        );
    }

    return [
        'kind' => 'token',
        'mode' => 'env',
        'ns'   => $m[2],
        'key'  => $m[3],
    ];
}

/**
 * Load and strictly validate the JSON store. Never returns a partial or
 * malformed store — any problem aborts.
 */
function secretsman_load_store(string $path): array
{
    if (!is_file($path)) {
        throw new SecretsmanError("secretsman: store not found at {$path}");
    }

    $perms = fileperms($path) & 0777;
    if ($perms !== 0600) {
        throw new SecretsmanError(sprintf(
            'secretsman: store at %s has insecure permissions %s (expected 0600)',
            $path,
            decoct($perms)
        ));
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new SecretsmanError("secretsman: store at {$path} could not be read");
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new SecretsmanError("secretsman: store at {$path} is not valid JSON");
    }

    if (!is_array($data)) {
        throw new SecretsmanError("secretsman: store at {$path} must be a JSON object of namespaces");
    }

    foreach ($data as $ns => $keys) {
        if (!is_string($ns) || !is_array($keys)) {
            throw new SecretsmanError("secretsman: store is malformed — namespace '{$ns}' is not an object");
        }
        foreach ($keys as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                throw new SecretsmanError("secretsman: store is malformed — '{$ns}/{$k}' is not a string value");
            }
            if (strpos($v, "\n") !== false || strpos($v, "\r") !== false) {
                throw new SecretsmanError(
                    "secretsman: value at '{$ns}/{$k}' contains a newline, which --env-file " .
                    "cannot carry safely; refusing to load the store"
                );
            }
        }
    }

    return $data;
}

function secretsman_lookup(array $store, string $ns, string $key): string
{
    if (!isset($store[$ns]) || !is_array($store[$ns])) {
        throw new SecretsmanError("secretsman: no such namespace '{$ns}' in store");
    }
    if (!array_key_exists($key, $store[$ns])) {
        throw new SecretsmanError("secretsman: no such key '{$key}' in namespace '{$ns}'");
    }
    return $store[$ns][$key];
}

/**
 * Atomic write of $value to $path, 0400, creating parent dirs as needed.
 * Despite the file-shaped name this is NOT a !secretfile remnant — it's the
 * only writer of the !secret env-file (secretsman_resolve()'s $envLines
 * branch below). Named for what it does (write a protected file), not for
 * which removed mode used to call it too.
 */
function secretsman_write_protected_file(string $path, string $value): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new SecretsmanError("secretsman: could not create directory {$dir}");
    }

    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $value) === false) {
        throw new SecretsmanError("secretsman: could not write {$path}");
    }
    chmod($tmp, 0400);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new SecretsmanError("secretsman: could not finalize {$path}");
    }
}

/** Delete stale env-files older than $maxAgeSeconds. */
function secretsman_sweep_env_dir(string $envDir, int $maxAgeSeconds = 300): void
{
    if (!is_dir($envDir)) {
        return;
    }
    $now = time();
    foreach (glob($envDir . '/*.env') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
}

/** The filesystem-safe form of a container name, used for its env-file basename. */
function secretsman_safe_name(string $containerName): string
{
    $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $containerName);
    return $safe !== '' ? $safe : 'container';
}

/** Default store path: SECRETSMAN_STORE_PATH env var, else the standard location. */
function secretsman_default_store_path(): string
{
    return getenv('SECRETSMAN_STORE_PATH') ?: '/mnt/user/appdata/.secrets/store.json';
}

/**
 * Validate a namespace/key pair against the exact same grammar
 * secretsman_parse_token() enforces — by round-tripping through it, rather
 * than restating the [A-Za-z0-9_.-]+ character class a second time. The
 * echo-back equality check (not just "did it parse") is what catches
 * leading/trailing whitespace: secretsman_parse_token() trims its input,
 * so " ns" would otherwise parse to "ns" and slip through undetected.
 */
function secretsman_check_name(string $ns, string $key): void
{
    try {
        $combined = secretsman_parse_token("!secret {$ns}/{$key}");
        if ($combined['kind'] === 'token' && $combined['ns'] === $ns && $combined['key'] === $key) {
            return; // valid
        }
    } catch (SecretsmanError $e) {
        // fall through to work out which field is at fault, below
    }

    // Probe the namespace alone (against a known-good placeholder key) to
    // report the specific offending field rather than a generic failure.
    $nsOk = false;
    try {
        $p = secretsman_parse_token("!secret {$ns}/placeholder");
        $nsOk = $p['kind'] === 'token' && $p['ns'] === $ns;
    } catch (SecretsmanError $e) {
        $nsOk = false;
    }

    $field = $nsOk ? 'key' : 'namespace';
    $bad   = $nsOk ? $key : $ns;
    throw new SecretsmanError(
        "secretsman: invalid {$field} '{$bad}' — namespaces and keys may contain only " .
        "letters, digits, and _ . - with no other characters"
    );
}

/**
 * Atomic store write: encode -> tmp file at 0600 -> validate via the real
 * secretsman_load_store() -> rename. This is the load-bearing step: every
 * shape/newline/permission rule the resolver enforces at container-create
 * time is enforced here too, against the exact bytes about to become the
 * store, by calling the same function rather than restating its rules.
 * On any failure the tmp file is removed and the real store is untouched.
 */
function secretsman_save_store(string $path, array $store): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new SecretsmanError("secretsman: could not create directory {$dir}");
    }

    $tmp = $path . '.tmp' . getmypid();
    $oldUmask = umask(0077); // tmp file must never be even momentarily world-readable
    try {
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json . "\n") === false) {
            throw new SecretsmanError("secretsman: could not write {$tmp}");
        }
        chmod($tmp, 0600);
    } finally {
        umask($oldUmask);
    }

    try {
        secretsman_load_store($tmp); // throws on anything the resolver itself would reject
    } catch (SecretsmanError $e) {
        @unlink($tmp);
        throw $e;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new SecretsmanError("secretsman: could not finalize {$path}");
    }
}

/**
 * Add ($overwrite=false) or edit ($overwrite=true) one secret. Value
 * validation (string-only, no newline) is deliberately not duplicated here
 * — it happens inside secretsman_save_store()'s validate-via-load_store
 * step, so a bad value produces the resolver's own --env-file-framed
 * message. Read-modify-write is last-write-wins; an flock() on $path would
 * be the upgrade if this ever has concurrent editors.
 */
function secretsman_store_set(string $path, string $ns, string $key, string $value, bool $overwrite = false): void
{
    secretsman_check_name($ns, $key);

    $store = is_file($path) ? secretsman_load_store($path) : [];

    if (!$overwrite && isset($store[$ns][$key])) {
        throw new SecretsmanError("secretsman: '{$ns}/{$key}' already exists — edit it instead of adding it");
    }

    $store[$ns][$key] = $value;
    ksort($store[$ns]);
    ksort($store);

    secretsman_save_store($path, $store);
}

/** Delete one secret; prunes the namespace too if it's now empty. */
function secretsman_store_delete(string $path, string $ns, string $key): void
{
    $store = secretsman_load_store($path);
    secretsman_lookup($store, $ns, $key); // throws the resolver's own "no such ..." on a miss

    unset($store[$ns][$key]);
    if ($store[$ns] === []) {
        unset($store[$ns]);
    }

    secretsman_save_store($path, $store);
}

/**
 * Scan saved templates for !secret usage, reusing secretsman_parse_token()
 * as the single source of truth for token detection — no parallel grammar.
 * Returns ['usage' => ['ns/key' => [containerName, ...]], 'problems' =>
 * [['container'=>,'field'=>,'message'=>], ...]]. Unparseable templates are
 * skipped silently (a broken template is Docker's problem, not ours).
 */
function secretsman_scan_templates(string $dir): array
{
    $usage = [];
    $problems = [];

    foreach (glob($dir . '/*.xml') ?: [] as $templatePath) {
        $prev = libxml_use_internal_errors(true);
        $xml = @simplexml_load_file($templatePath);
        libxml_use_internal_errors($prev);
        if ($xml === false || !isset($xml->Config)) {
            continue;
        }

        $name = (string)($xml->Name ?? basename($templatePath));

        foreach ($xml->Config as $config) {
            $type = strtolower((string)($config['Type'] ?? ''));
            if ($type !== 'variable') {
                continue;
            }
            $value = (string)$config;
            $rawValue = strlen($value) ? $value : (string)($config['Default'] ?? '');

            try {
                $parsed = secretsman_parse_token($rawValue);
            } catch (SecretsmanError $e) {
                $problems[] = [
                    'container' => $name,
                    'field'     => (string)($config['Target'] ?? ($config['Name'] ?? '?')),
                    'message'   => $e->getMessage(),
                ];
                continue;
            }

            if ($parsed['kind'] === 'token') {
                $usage["{$parsed['ns']}/{$parsed['key']}"][] = $name;
            }
        }
    }

    return ['usage' => $usage, 'problems' => $problems];
}

/**
 * Resolve every !secret token in $xml['Config'], mutating $xml in place.
 * (A !secretfile token is recognised only to abort with a message pointing
 * at !secret — see secretsman_parse_token().) Tokens are only permitted in
 * Variable-type fields; a
 * token anywhere else (Path, Port, Label, Device) aborts, naming the
 * field. A token in ExtraParams or PostArgs (free-text fields, not Config
 * entries) also aborts explicitly, since those are echoed into $cmd raw.
 *
 * $opts:
 *   store_path    default '/mnt/user/appdata/.secrets/store.json', overridable
 *                 by the SECRETSMAN_STORE_PATH env var when $opts doesn't set it
 *   runtime_root  default '/run/secretsman', overridable by SECRETSMAN_RUNTIME_ROOT
 *
 * The env-var fallback exists solely so the Phase 2 staging harness
 * (tests/harness/) can exercise the exact production code path — the real
 * shipped patch calls secretsman_resolve($xml, $xml['Name']) with no
 * $opts at all — against a throwaway fixture store instead of the real
 * one, without touching /mnt/user/appdata. The .plg never sets these.
 */
function secretsman_resolve(array &$xml, string $containerName, array $opts = []): void
{
    $storePath   = $opts['store_path']   ?? secretsman_default_store_path();
    $runtimeRoot = $opts['runtime_root'] ?? (getenv('SECRETSMAN_RUNTIME_ROOT') ?: '/run/secretsman');
    $safeName    = secretsman_safe_name($containerName);

    $envDir = $runtimeRoot . '/env';

    secretsman_sweep_env_dir($envDir);

    // Free-text fields never go through Config — a token there would be
    // echoed straight into $cmd, so reject it explicitly up front.
    foreach (['ExtraParams', 'PostArgs'] as $freeTextField) {
        $ftValue = (string)($xml[$freeTextField] ?? '');
        if (stripos($ftValue, '!secret') !== false) {
            throw new SecretsmanError(
                "secretsman: tokens are not supported in {$freeTextField} (it is inserted " .
                "directly into the docker command line)"
            );
        }
    }

    $store     = null; // lazy: only load the store if a token is actually found
    $envLines  = [];
    $newConfig = [];

    foreach ($xml['Config'] as $config) {
        $type     = strtolower((string)($config['Type'] ?? ''));
        $rawValue = strlen((string)($config['Value'] ?? ''))
            ? $config['Value']
            : (string)($config['Default'] ?? '');

        $parsed = secretsman_parse_token((string)$rawValue);

        if ($parsed['kind'] === 'none') {
            $newConfig[] = $config;
            continue;
        }

        if ($parsed['kind'] === 'literal') {
            $config['Value'] = $parsed['value'];
            $newConfig[] = $config;
            continue;
        }

        // kind === 'token'
        if ($type !== 'variable') {
            throw new SecretsmanError(sprintf(
                "secretsman: tokens are not supported in %s fields (found in '%s')",
                $config['Type'] ?? $type,
                $config['Target'] ?? ($config['Name'] ?? '?')
            ));
        }

        if ($store === null) {
            $store = secretsman_load_store($storePath);
        }
        $value = secretsman_lookup($store, $parsed['ns'], $parsed['key']);

        // mode is always 'env' now — a 'file' token already threw in
        // secretsman_parse_token() above.
        $envLines[] = $config['Target'] . '=' . $value;
        // Entry dropped entirely: no -e is emitted for it at all.
    }

    $xml['Config'] = $newConfig;

    if ($envLines) {
        $envPath = $envDir . '/' . $safeName . '.env';
        secretsman_write_protected_file($envPath, implode("\n", $envLines) . "\n");
        $xml['ExtraParams'] = rtrim((string)($xml['ExtraParams'] ?? ''))
            . ' --env-file=' . escapeshellarg($envPath);
    }
}
