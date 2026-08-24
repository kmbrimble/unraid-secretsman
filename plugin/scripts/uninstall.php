<?php
declare(strict_types=1);

/**
 * Cleanly reverts the live Helpers.php to stock by stripping the marker-
 * delimited patch block (secretsman_patch_revert(), src/patch.php). Run
 * by the .plg's Remove action.
 *
 * Note this is a convenience, not the safety net: a reboot alone already
 * restores Helpers.php from the OS image regardless of whether this ever
 * runs (see RECOVERY.md) — /usr/local/emhttp is never persisted. This
 * script exists so "Remove plugin" cleans up immediately, without making
 * the user reboot to get back to stock.
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/patch.php";
require_once __DIR__ . '/common.php';


function uninstall_main(): int
{
    if (!is_file(SECRETSMAN_LIVE_HELPERS_PATH)) {
        fwrite(STDOUT, "secretsman: " . SECRETSMAN_LIVE_HELPERS_PATH . " does not exist, nothing to revert\n");
        return 0;
    }

    $contents = file_get_contents(SECRETSMAN_LIVE_HELPERS_PATH);
    if ($contents === false) {
        fwrite(STDERR, 'secretsman: could not read ' . SECRETSMAN_LIVE_HELPERS_PATH . "\n");
        return 1;
    }

    if (!secretsman_patch_is_applied($contents)) {
        fwrite(STDOUT, "secretsman: Helpers.php is not patched, nothing to revert\n");
        return 0;
    }

    try {
        $reverted = secretsman_patch_revert($contents);
    } catch (SecretsmanPatchError $e) {
        fwrite(STDERR, "secretsman: {$e->getMessage()} — leaving the file as-is. Reboot to restore stock (see RECOVERY.md).\n");
        return 1;
    }

    $tmp = SECRETSMAN_LIVE_HELPERS_PATH . '.secretsman-tmp' . getmypid();
    if (@file_put_contents($tmp, $reverted) === false) {
        fwrite(STDERR, "secretsman: could not write {$tmp}\n");
        return 1;
    }
    $perms = fileperms(SECRETSMAN_LIVE_HELPERS_PATH);
    if ($perms !== false) {
        @chmod($tmp, $perms & 0777);
    }
    if (!@rename($tmp, SECRETSMAN_LIVE_HELPERS_PATH)) {
        @unlink($tmp);
        fwrite(STDERR, "secretsman: could not finalize revert to " . SECRETSMAN_LIVE_HELPERS_PATH . "\n");
        return 1;
    }

    fwrite(STDOUT, "secretsman: Helpers.php reverted to stock\n");
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(uninstall_main());
}
