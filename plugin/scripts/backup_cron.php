<?php
declare(strict_types=1);

/**
 * The script cron actually runs (see backup_cron_register.php for how the
 * schedule line gets there). CLI context, so — unlike backup_api.php —
 * it's fine to use common.php's secretsman_notify(): a scheduled backup
 * that fails should raise a real Unraid notification, since nobody is
 * watching a browser tab when cron fires. Notifies on failure only,
 * matching appdata.backup's own default ("notification: error").
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/secretsman.php";
require_once "{$pluginDir}/src/backup.php";
require_once __DIR__ . '/common.php';

function backup_cron_main(): int
{
    $config = secretsman_backup_config_load();
    $destination = $config['destination'] ?? '';
    if ($destination === '') {
        // Not configured — nothing to do, and not a failure worth notifying about.
        return 0;
    }

    $password = secretsman_backup_password_load();
    if ($password === null) {
        secretsman_notify(
            'Scheduled secret-store backup skipped',
            'secretsman: no backup password is set. Configure one on the SecretsMan settings page.',
            'warning'
        );
        return 1;
    }

    try {
        $result = secretsman_backup_create(secretsman_default_store_path(), $password, $destination);
        $deleted = secretsman_backup_prune($destination, (int)($config['retention'] ?? 3));
        $config['lastRun'] = ['time' => time(), 'ok' => true, 'message' => "backed up via {$result['tool']}"];
        secretsman_backup_config_save($config);
        fwrite(STDOUT, "secretsman: backup ok: {$result['path']} ({$result['bytes']} bytes)\n");
        if ($deleted) {
            fwrite(STDOUT, 'secretsman: pruned: ' . implode(', ', $deleted) . "\n");
        }
        return 0;
    } catch (SecretsmanError $e) {
        $config['lastRun'] = ['time' => time(), 'ok' => false, 'message' => $e->getMessage()];
        secretsman_backup_config_save($config);
        secretsman_notify(
            'Scheduled secret-store backup failed',
            $e->getMessage(),
            'alert'
        );
        fwrite(STDERR, "secretsman: backup failed: {$e->getMessage()}\n");
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(backup_cron_main());
}
