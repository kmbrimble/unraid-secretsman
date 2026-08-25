<?php
declare(strict_types=1);

/**
 * The actual backup logic — called both by cron (via the CLI guard below)
 * and by backup_api.php's backup_now action, so a manual "Back up now"
 * click and a scheduled run share exactly one implementation instead of
 * two copies drifting apart.
 *
 * backup_cron_main() deliberately never touches STDOUT/STDERR directly —
 * both are CLI-SAPI-only constants, undefined under php-fpm (see CLAUDE.md
 * rule 8 and its dated standing note — this is the same class of bug that
 * shipped once already in backup_cron_register.php). It returns a result
 * instead; only the CLI invocation guard at the bottom of this file, which
 * never runs when this script is require_once'd from a web script, prints
 * anything. secretsman_notify() (via common.php) IS safe to call from
 * either context — it uses error_log(), not STDERR — so a scheduled
 * failure still raises a real Unraid notification when nobody's watching a
 * browser tab; a manual backup_now failure raises the same notification as
 * a side effect, which is harmless (in addition to, not instead of, the
 * error the page shows inline).
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/secretsman.php";
require_once "{$pluginDir}/src/backup.php";
require_once __DIR__ . '/common.php';

function backup_cron_main(): array
{
    $config = secretsman_backup_config_load();
    $destination = (string)($config['destination'] ?? '');
    if ($destination === '') {
        // Not configured. From cron this isn't a failure worth notifying
        // about (no notify() call); from backup_now this becomes a clear
        // inline error via the same message either way.
        return ['ok' => false, 'message' => 'secretsman: set a destination before backing up'];
    }

    $password = secretsman_backup_password_load();
    if ($password === null) {
        secretsman_notify(
            'Scheduled secret-store backup skipped',
            'secretsman: no backup password is set. Configure one on the SecretsMan settings page.',
            'warning'
        );
        return ['ok' => false, 'message' => 'secretsman: set a backup password before backing up'];
    }

    try {
        $result = secretsman_backup_create(secretsman_default_store_path(), $password, $destination);
        $deleted = secretsman_backup_prune($destination, (int)($config['retention'] ?? 3));
        $config['lastRun'] = ['time' => time(), 'ok' => true, 'message' => "backed up via {$result['tool']}"];
        secretsman_backup_config_save($config);
        $message = "secretsman: backup ok: {$result['path']} ({$result['bytes']} bytes)";
        if ($deleted) {
            $message .= '; pruned: ' . implode(', ', $deleted);
        }
        return ['ok' => true, 'message' => $message, 'result' => $result];
    } catch (SecretsmanError $e) {
        $config['lastRun'] = ['time' => time(), 'ok' => false, 'message' => $e->getMessage()];
        secretsman_backup_config_save($config);
        secretsman_notify(
            'Scheduled secret-store backup failed',
            $e->getMessage(),
            'alert'
        );
        return ['ok' => false, 'message' => "secretsman: backup failed: {$e->getMessage()}"];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = backup_cron_main();
    fwrite($result['ok'] ? STDOUT : STDERR, $result['message'] . "\n");
    exit($result['ok'] ? 0 : 1);
}
