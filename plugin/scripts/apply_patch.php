<?php
declare(strict_types=1);

/**
 * Boot-time (and manual) patch application. Invoked by the .plg's install
 * block, which re-runs on every boot via the standard .plg-reinstall-at-
 * rc.local mechanism — see CLAUDE.md "Boot-time patch pattern".
 *
 * Determines the running Unraid version, looks up its known-good hash set
 * from the plugin's own bundled reference/<version>/HASHES, and only
 * patches on an exact match. Any other outcome — unknown version, hash
 * mismatch, already patched — is handled without ever risking a partial
 * or guessed patch. See CLAUDE.md rule 4.
 *
 *   php apply_patch.php
 *
 * Exit code is 0 for every *expected* outcome (patched, already-patched,
 * mismatch-notified, unknown-version-notified) — none of those are boot
 * failures. Only a genuine script error (can't read its own files) exits
 * non-zero, so rc.local doesn't need special-case handling per outcome.
 */

$pluginDir = dirname(__DIR__);
require_once "{$pluginDir}/src/patch.php";
require_once __DIR__ . '/common.php';

const UNRAID_VERSION_FILE = '/etc/unraid-version';

/** Detect the running Unraid version's major.minor, mapped to our reference/<x>.<y>.x/ convention. */
function detect_unraid_reference_dir(string $versionFile = UNRAID_VERSION_FILE): ?string
{
    if (!is_file($versionFile)) {
        return null;
    }
    $contents = file_get_contents($versionFile);
    if ($contents === false || !preg_match('/version="(\d+)\.(\d+)\.\d+"/', $contents, $m)) {
        return null;
    }
    return "{$m[1]}.{$m[2]}.x";
}

/** Parse a HASHES file into the list of acceptable md5s for Helpers.php. */
function load_known_good_hashes(string $hashesFile): array
{
    if (!is_file($hashesFile)) {
        return [];
    }
    $hashes = [];
    foreach (file($hashesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^Helpers\.php\s+md5:([0-9a-f]{32})$/', trim($line), $m)) {
            $hashes[] = $m[1];
        }
    }
    return $hashes;
}

function apply_patch_main(): int
{
    $pluginDir = dirname(__DIR__);
    $libPath = "{$pluginDir}/src/secretsman.php";

    $refDir = detect_unraid_reference_dir();
    if ($refDir === null) {
        secretsman_notify(
            'Unraid version could not be determined',
            'secretsman: could not read/parse ' . UNRAID_VERSION_FILE . ' — patch NOT applied, system left working unpatched',
            'alert',
            '/Plugins'
        );
        return 0;
    }

    $hashesFile = "{$pluginDir}/reference/{$refDir}/HASHES";
    $knownGood = load_known_good_hashes($hashesFile);
    if (!$knownGood) {
        secretsman_notify(
            "No known-good hash for Unraid {$refDir}",
            "secretsman: this plugin has not been verified against Unraid {$refDir} " .
            '(no reference/' . $refDir . '/HASHES bundled) — patch NOT applied, system left working unpatched',
            'alert',
            '/Plugins'
        );
        return 0;
    }

    try {
        $result = secretsman_patch_apply_to_file(SECRETSMAN_LIVE_HELPERS_PATH, $libPath, $knownGood);
    } catch (\Throwable $e) {
        // A genuine failure to read/write, not a hash mismatch (which
        // secretsman_patch_apply_to_file returns as a status, not a throw).
        secretsman_notify(
            'Patch could not be applied',
            'secretsman: ' . get_class($e) . ' while patching Helpers.php — system left as-is, please investigate',
            'alert',
            '/Plugins'
        );
        fwrite(STDERR, 'secretsman: ' . $e->getMessage() . "\n");
        return 0;
    }

    switch ($result['status']) {
        case 'already-patched':
            fwrite(STDOUT, "secretsman: Helpers.php already patched, no-op\n");
            break;
        case 'unpatched-known-good':
            fwrite(STDOUT, "secretsman: Helpers.php patched (was {$result['hash']})\n");
            break;
        case 'mismatch':
            secretsman_notify(
                'Helpers.php does not match a known-good hash',
                "secretsman: {$refDir} Helpers.php hash {$result['hash']} is not recognised — an Unraid " .
                'update likely changed this file. Patch NOT applied; the system is left working, unpatched. ' .
                'A human needs to verify the new file and add its hash to reference/' . $refDir . '/HASHES ' .
                'before this plugin will patch it.',
                'alert',
                '/Plugins'
            );
            break;
    }

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(apply_patch_main());
}
