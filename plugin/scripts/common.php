<?php
declare(strict_types=1);

/**
 * Shared helpers for the plugin's boot/CLI scripts (apply_patch.php,
 * uninstall.php). Not part of src/ — this is Unraid environment glue
 * (notify, the live Helpers.php path), not version-independent resolver
 * logic.
 */

const SECRETSMAN_NOTIFY_BIN = '/usr/local/emhttp/webGui/scripts/notify';
const SECRETSMAN_LIVE_HELPERS_PATH = '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

function secretsman_notify(string $subject, string $description, string $importance = 'warning', string $link = '/Docker'): void
{
    if (!is_executable(SECRETSMAN_NOTIFY_BIN)) {
        fwrite(STDERR, "secretsman: notify script not found, would have said: {$subject}: {$description}\n");
        return;
    }
    $cmd = sprintf(
        '%s -e %s -s %s -d %s -i %s -l %s >/dev/null 2>&1',
        escapeshellarg(SECRETSMAN_NOTIFY_BIN),
        escapeshellarg('secretsman'),
        escapeshellarg($subject),
        escapeshellarg($description),
        escapeshellarg($importance),
        escapeshellarg($link)
    );
    exec($cmd);
}
