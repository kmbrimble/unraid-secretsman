<?php
declare(strict_types=1);

/**
 * Shared helpers for the plugin's boot/CLI scripts (apply_patch.php,
 * uninstall.php, backup_cron.php, backup_cron_register.php). Not part of
 * src/ — this is Unraid environment glue (notify, live paths), not
 * version-independent resolver/backup logic.
 *
 * secretsman_notify() uses error_log(), not fwrite(STDERR, ...), on
 * purpose: STDERR is a CLI-SAPI-only constant, undefined under php-fpm, and
 * backup_cron_register.php (which requires this file) is itself reachable
 * from backup_api.php's web request context — this is no longer a
 * theoretical risk kept out only by every current caller avoiding it. See
 * CLAUDE.md's dated standing note on this exact class of bug (it already
 * fired once, in backup_cron_register.php directly, before this one was
 * fixed pre-emptively).
 */

const SECRETSMAN_NOTIFY_BIN = '/usr/local/emhttp/webGui/scripts/notify';
const SECRETSMAN_LIVE_HELPERS_PATH = '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';
const SECRETSMAN_BACKUP_CRON_FILE = '/boot/config/plugins/unraid-secretsman/unraid-secretsman.cron';
const SECRETSMAN_BACKUP_CRON_SCRIPT = '/usr/local/emhttp/plugins/unraid-secretsman/scripts/backup_cron.php';

function secretsman_notify(string $subject, string $description, string $importance = 'warning', string $link = '/Docker'): void
{
    if (!is_executable(SECRETSMAN_NOTIFY_BIN)) {
        error_log("secretsman: notify script not found, would have said: {$subject}: {$description}");
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
