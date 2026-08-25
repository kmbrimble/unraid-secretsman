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
    $storePath   = $opts['store_path']   ?? (getenv('SECRETSMAN_STORE_PATH') ?: '/mnt/user/appdata/.secrets/store.json');
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
