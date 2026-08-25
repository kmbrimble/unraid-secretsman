<?php
declare(strict_types=1);

/**
 * Regenerates the flash .cron file from backup-config.json and tells crond
 * about it — appdata.backup's own "checkCron.php" pattern (see its .plg
 * changelog: "Scheduling got lost after reboot" from writing straight to
 * /etc/cron.d, which is Unraid's RAM rootfs and is wiped every boot).
 *
 * Regenerates from config every time it runs, rather than trusting that the
 * existing .cron file is correct or even present — the same "don't trust
 * the stored artifact" discipline the store's own load path uses.
 *
 * Called from two places: backup_api.php when the schedule is saved (a web
 * request, php-fpm), and the .plg's install block on every boot (CLI, .plg
 * reinstalls unconditionally every boot — see CLAUDE.md "Boot-time patch
 * pattern" — and update_cron only touches /boot/config and /etc/cron.d,
 * neither of which needs the array mounted, so no event/disks_mounted hook
 * is needed for this).
 *
 * backup_cron_register_main() deliberately never touches STDOUT/STDERR —
 * both are CLI-SAPI-only constants, undefined under php-fpm, and calling
 * this function from backup_api.php's web request context previously
 * fataled with "Undefined constant STDOUT" (a live 500, caught and fixed).
 * It returns a result instead; only the CLI invocation guard at the bottom
 * of this file — which only runs when this script IS the one being
 * executed, never when it's require_once'd from a web script — prints
 * anything.
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/secretsman.php";
require_once "{$pluginDir}/src/backup.php";
require_once __DIR__ . '/common.php';

function backup_cron_register_main(): array
{
    $config = secretsman_backup_config_load();
    $line = secretsman_backup_cron_line($config['schedule'] ?? [], SECRETSMAN_BACKUP_CRON_SCRIPT);

    $dir = dirname(SECRETSMAN_BACKUP_CRON_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => "secretsman: could not create {$dir}"];
    }

    if ($line === null) {
        // Schedule is off: remove any stale .cron file rather than leaving
        // a schedule active that the GUI says is disabled.
        @unlink(SECRETSMAN_BACKUP_CRON_FILE);
    } else {
        $contents = "# secretsman backup schedule\n{$line}\n";
        if (@file_put_contents(SECRETSMAN_BACKUP_CRON_FILE, $contents) === false) {
            return ['ok' => false, 'message' => 'secretsman: could not write ' . SECRETSMAN_BACKUP_CRON_FILE];
        }
    }

    exec('/usr/local/sbin/update_cron', $out, $exit);
    if ($exit !== 0) {
        return ['ok' => false, 'message' => "secretsman: update_cron exited {$exit}: " . implode("\n", $out)];
    }

    return ['ok' => true, 'message' => $line === null
        ? 'secretsman: backup schedule is off, cron entry removed'
        : "secretsman: backup cron registered: {$line}"];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = backup_cron_register_main();
    fwrite($result['ok'] ? STDOUT : STDERR, $result['message'] . "\n");
    exit($result['ok'] ? 0 : 1);
}
