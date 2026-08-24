<?php
declare(strict_types=1);

/**
 * RECOVERY.md's single-command override for a container held back because
 * repopulate.php couldn't materialise one of its !secretfile sources
 * (typically: the underlying problem is now fixed, but the array hasn't
 * been restarted since, so disks_mounted hasn't re-fired).
 *
 * Deliberately does NOT bypass the fail-closed check. It re-attempts
 * repopulation for THIS ONE container right now, and only runs
 * `docker start` if that succeeds — never forces a start against a
 * container still missing its secret file. If you actually want that
 * (accepting an empty directory gets bind-mounted in its place), do it
 * yourself with `docker start <name>` directly; this script won't.
 *
 *   php force_start.php <ContainerName>
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/secretsman.php";
require_once __DIR__ . '/common.php';

function force_start_main(int $argc, array $argv): int
{
    if ($argc !== 2 || $argv[1] === '') {
        fwrite(STDERR, "usage: php force_start.php <ContainerName>\n");
        return 2;
    }
    $name = $argv[1];

    $templatePath = secretsman_template_path_for($name);
    if (!is_file($templatePath)) {
        fwrite(STDERR, "secretsman: no saved template for \"{$name}\" at {$templatePath} — check the container name\n");
        return 1;
    }

    $tokens = secretsman_find_file_tokens($templatePath);
    if (!$tokens) {
        fwrite(STDOUT, "secretsman: \"{$name}\" has no !secretfile tokens — nothing for this script to do. If it's still not starting, the problem isn't a missing secret; check `docker start {$name}` directly.\n");
        return 1;
    }

    try {
        $store = secretsman_load_store(getenv('SECRETSMAN_STORE_PATH') ?: '/mnt/user/appdata/.secrets/store.json');
    } catch (SecretsmanError $e) {
        fwrite(STDERR, "secretsman: store still unavailable — {$e->getMessage()}\n");
        return 1;
    }

    $runtimeRoot = getenv('SECRETSMAN_RUNTIME_ROOT') ?: '/run/secretsman';
    $safeName = secretsman_safe_name($name);
    $failed = [];
    foreach ($tokens as $token) {
        try {
            $value = secretsman_lookup($store, $token['ns'], $token['key']);
            secretsman_write_secret_file("{$runtimeRoot}/files/{$safeName}/{$token['key']}", $value);
        } catch (SecretsmanError $e) {
            $failed[] = "{$token['ns']}/{$token['key']}: {$e->getMessage()}";
        }
    }

    if ($failed) {
        fwrite(STDERR, "secretsman: still can't repopulate \"{$name}\" — not starting it:\n  " . implode("\n  ", $failed) . "\n");
        return 1;
    }

    fwrite(STDOUT, "secretsman: \"{$name}\" repopulated, starting it now\n");
    exec('docker start ' . escapeshellarg($name) . ' 2>&1', $out, $code);
    fwrite(STDOUT, implode("\n", $out) . "\n");
    return $code === 0 ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(force_start_main($argc, $argv));
}
