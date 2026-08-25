<?php
declare(strict_types=1);

/**
 * secretsman staging harness — the regression test that matters (2c).
 *
 * For every REAL template on the live host, renders the docker command
 * with stock Helpers.php and with a patched copy, and requires the two be
 * byte-identical for any template that carries no secretsman token. Also
 * runs the committed token-bearing fixture template through the patched
 * copy and asserts the resolved sentinel values appear nowhere in the
 * rendered command.
 *
 * Deliberately prints only filenames, counts, and short reasons — never
 * full command content, which for real templates may contain real host
 * paths, ports, or other values the templates already hold in plaintext.
 * This script's own output is safe to paste back into a chat session
 * (which is rather the point of the whole project).
 *
 * Run ON the live host, read-only ($create_paths=false throughout —
 * enforced in render_cmd.php, not re-litigated here):
 *
 *   php regression.php <stock-helpers.php> <patched-helpers.php> <real-templates-dir>
 */

if ($argc !== 4) {
    fwrite(STDERR, "usage: php regression.php <stock-helpers.php> <patched-helpers.php> <real-templates-dir>\n");
    exit(2);
}
[, $stockHelpers, $patchedHelpers, $templatesDir] = $argv;

$here = __DIR__;
$renderScript = $here . '/render_cmd.php';
$phpBin = PHP_BINARY;

function render(string $phpBin, string $renderScript, string $helpers, string $template, array $env = []): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = [$phpBin, $renderScript, $helpers, $template];
    $proc = proc_open($cmd, $descriptors, $pipes, null, $env ?: null);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'stdout' => rtrim($stdout, "\n"), 'stderr' => trim($stderr)];
}

// ---------------------------------------------------------------------------
// Part 1: byte-identical check across every real, token-free template
// ---------------------------------------------------------------------------

$templates = glob(rtrim($templatesDir, '/') . '/*.xml') ?: [];
if (!$templates) {
    fwrite(STDERR, "no templates found in {$templatesDir}\n");
    exit(2);
}

$checked = 0;
$mismatches = 0;
$errors = 0;
$tokenBearingSkipped = 0;
$mismatchNames = [];
$errorNames = [];

foreach ($templates as $tpl) {
    $name = basename($tpl);
    $stock = render($phpBin, $renderScript, $stockHelpers, $tpl);

    if ($stock['code'] !== 0) {
        // Stock Helpers.php failing on one of the user's own real templates
        // is pre-existing and not ours to fix — note it and move on rather
        // than treating it as a patch regression.
        fwrite(STDERR, "SKIP  {$name}: stock render failed (pre-existing): {$stock['stderr']}\n");
        continue;
    }

    if (str_contains($stock['stdout'], '!secret')) {
        // A real template already using the token syntax (shouldn't exist
        // pre-Phase-2, but if it does, stock output containing the literal
        // token is expected — not part of the byte-identical contract).
        $tokenBearingSkipped++;
        continue;
    }

    $checked++;
    $patched = render($phpBin, $renderScript, $patchedHelpers, $tpl);

    if ($patched['code'] !== 0) {
        $errors++;
        $errorNames[] = $name;
        fwrite(STDERR, "FAIL  {$name}: patched copy errored on a token-free template: {$patched['stderr']}\n");
        continue;
    }

    if ($stock['stdout'] !== $patched['stdout']) {
        $mismatches++;
        $mismatchNames[] = $name;
        fwrite(STDERR, "FAIL  {$name}: stock and patched output DIFFER on a token-free template\n");
    }
}

printf(
    "byte-identical check: %d templates checked, %d identical, %d mismatches, %d errors, %d token-bearing skipped\n",
    $checked,
    $checked - $mismatches - $errors,
    $mismatches,
    $errors,
    $tokenBearingSkipped
);
if ($mismatchNames) {
    fwrite(STDERR, 'mismatched: ' . implode(', ', $mismatchNames) . "\n");
}
if ($errorNames) {
    fwrite(STDERR, 'errored: ' . implode(', ', $errorNames) . "\n");
}

// ---------------------------------------------------------------------------
// Part 2: token-bearing fixture through the patched copy — never leak
// ---------------------------------------------------------------------------

$fixtureTemplate = $here . '/fixtures/token-bearing.xml';
$fixtureStore = $here . '/fixtures/fixture-store.json';
$fixtureRuntimeRoot = sys_get_temp_dir() . '/secretsman-harness-runtime-' . bin2hex(random_bytes(6));

// The fixture store ships in git at whatever mode checkout gives it;
// secretsman_load_store() requires exactly 0600, so pin it here rather
// than depending on git preserving file modes (it doesn't, reliably).
chmod($fixtureStore, 0600);

$fixtureResult = render($phpBin, $renderScript, $patchedHelpers, $fixtureTemplate, [
    'SECRETSMAN_STORE_PATH' => $fixtureStore,
    'SECRETSMAN_RUNTIME_ROOT' => $fixtureRuntimeRoot,
]);

$fixtureOk = true;
if ($fixtureResult['code'] !== 0) {
    $fixtureOk = false;
    fwrite(STDERR, "FAIL  token-bearing fixture: patched render errored: {$fixtureResult['stderr']}\n");
} else {
    $sentinels = ['HARNESS-SENTINEL-ENV-VALUE-NOT-A-REAL-SECRET'];
    foreach ($sentinels as $sentinel) {
        if (str_contains($fixtureResult['stdout'], $sentinel)) {
            $fixtureOk = false;
            fwrite(STDERR, "FAIL  token-bearing fixture: a resolved sentinel value appears in \$cmd\n");
        }
    }
    if (!str_contains($fixtureResult['stdout'], '--env-file=')) {
        $fixtureOk = false;
        fwrite(STDERR, "FAIL  token-bearing fixture: expected --env-file= in the rendered command, not found\n");
    }
    if (str_contains($fixtureResult['stdout'], '!secret')) {
        $fixtureOk = false;
        fwrite(STDERR, "FAIL  token-bearing fixture: a literal token leaked through unresolved\n");
    }
}

echo $fixtureOk
    ? "token-bearing fixture check: PASS (no resolved value appears in \$cmd)\n"
    : "token-bearing fixture check: FAIL\n";

exit(($mismatches === 0 && $errors === 0 && $fixtureOk) ? 0 : 1);
