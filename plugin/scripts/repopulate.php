<?php
declare(strict_types=1);

/**
 * !secretfile repopulation, run at array start (see CLAUDE.md "Array-start
 * hook" — this is invoked from event/disks_mounted, NOT event/docker_started;
 * that ordering distinction is load-bearing, see the recon note it links to).
 *
 * tmpfs does not survive a reboot, so every !secretfile bind-mount source
 * needs rewriting here before docker autostart gets a chance to run.
 *
 * Deliberately does NOT implement any container-blocking logic of its own.
 * Unraid's own rc.docker autostart loop already calls container_paths_exist()
 * before starting each container, which inspects the container's configured
 * bind-mount sources and refuses to start it if any are missing — exactly
 * the "hold back this one container" behaviour Phase 2 needs, already
 * built into stock Unraid (see /etc/rc.d/rc.docker:276). This script's only
 * job is to win the race: make sure every recoverable file exists again
 * before that check runs, and raise clear notifications (naming the
 * container and the missing key, not just an opaque tmpfs path) for the
 * ones it can't recover — the native check then quietly leaves those
 * containers stopped, no separate "blocking" mechanism required.
 *
 * A store-level failure (unreadable, malformed, wrong permissions) is
 * reported ONCE as a systemic notification, never as one failure per
 * affected container — see CLAUDE.md rule on distinguishing failure classes.
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/secretsman.php";
require_once __DIR__ . '/common.php';

const AUTOSTART_FILE = '/var/lib/docker/unraid-autostart';

/** Container names currently configured to autostart, in file order. */
function read_autostart_list(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return null;
    }
    $names = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if ($parts && $parts[0] !== '') {
            $names[] = $parts[0];
        }
    }
    return $names;
}

function repopulate_main(): int
{
    $storePath = getenv('SECRETSMAN_STORE_PATH') ?: '/mnt/user/appdata/.secrets/store.json';
    $runtimeRoot = getenv('SECRETSMAN_RUNTIME_ROOT') ?: '/run/secretsman';

    $autostart = read_autostart_list(AUTOSTART_FILE);
    if ($autostart === null) {
        secretsman_notify(
            'Could not read the docker autostart list',
            'secretsman: ' . AUTOSTART_FILE . ' is missing or unreadable — skipping !secretfile ' .
            'repopulation for this array start. Any autostarting container using !secretfile will ' .
            'be held back by Unraid\'s own container_paths_exist check until repopulated manually.'
        );
        return 0;
    }

    // Only bother loading the store if at least one autostart container
    // even has a template on disk — avoids a notification for hosts that
    // don't use !secretfile at all.
    $candidates = [];
    foreach ($autostart as $name) {
        $templatePath = secretsman_template_path_for($name);
        if (!is_file($templatePath)) {
            continue;
        }
        $tokens = secretsman_find_file_tokens($templatePath);
        if ($tokens) {
            $candidates[$name] = $tokens;
        }
    }
    if (!$candidates) {
        return 0;
    }

    try {
        $store = secretsman_load_store($storePath);
    } catch (SecretsmanError $e) {
        // Systemic: every !secretfile container is affected for the same
        // one reason. Report it once, not once per container.
        secretsman_notify(
            'Secret store unavailable — !secretfile containers may not autostart',
            'secretsman: ' . $e->getMessage() . '. ' . count($candidates) . ' autostart container(s) use ' .
            '!secretfile and will be held back by Unraid\'s own container_paths_exist check until this ' .
            'is fixed and the array is restarted (or use the force-start override in RECOVERY.md).',
            'alert'
        );
        return 0;
    }

    foreach ($candidates as $name => $tokens) {
        $safeName = secretsman_safe_name($name);
        $failedKeys = [];
        foreach ($tokens as $token) {
            try {
                $value = secretsman_lookup($store, $token['ns'], $token['key']);
                $hostPath = "{$runtimeRoot}/files/{$safeName}/{$token['key']}";
                secretsman_write_secret_file($hostPath, $value);
            } catch (SecretsmanError $e) {
                $failedKeys[] = "{$token['ns']}/{$token['key']}";
            }
        }
        if ($failedKeys) {
            secretsman_notify(
                "Container \"{$name}\" held back — secret unavailable",
                "secretsman: could not repopulate " . implode(', ', $failedKeys) . " for \"{$name}\". " .
                'This container will not autostart (Unraid\'s container_paths_exist check will keep it ' .
                'stopped since its secret file is missing). Fix the store and restart the array, or see ' .
                'RECOVERY.md for a force-start override.'
            );
        }
    }

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(repopulate_main());
}
