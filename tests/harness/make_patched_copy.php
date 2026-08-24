<?php
declare(strict_types=1);

/**
 * Produces a patched COPY of a stock Helpers.php — never touches the
 * source file. This is step 1 of the 2c staging harness: copy stock to
 * /tmp, patch the copy, php -l it, before anything live is ever touched.
 *
 *   php make_patched_copy.php <source-helpers.php> <dest-path> <lib-path>
 *
 * <lib-path> is the require_once path baked into the patched copy — the
 * plugin's own installed src/secretsman.php location.
 */

require __DIR__ . '/../../src/patch.php';

if ($argc !== 4) {
    fwrite(STDERR, "usage: php make_patched_copy.php <source-helpers.php> <dest-path> <lib-path>\n");
    exit(2);
}
[, $source, $dest, $libPath] = $argv;

try {
    $contents = file_get_contents($source);
    if ($contents === false) {
        throw new \RuntimeException("could not read {$source}");
    }
    $patched = secretsman_patch_apply($contents, $libPath);
    if (file_put_contents($dest, $patched) === false) {
        throw new \RuntimeException("could not write {$dest}");
    }
    echo "patched copy written to {$dest}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
