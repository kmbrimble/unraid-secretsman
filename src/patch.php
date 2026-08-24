<?php
declare(strict_types=1);

/**
 * secretsman patch layer — surgical, checksum-guarded injection of a single
 * call to secretsman_resolve() into Unraid's xmlToCommand(), never a
 * whole-file replacement.
 *
 * See CLAUDE.md "Insertion point" for why this exact anchor line is safe:
 * at that point in xmlToCommand(), Config values are still unescaped, and
 * mutating $xml['Config'] there lets the function's own existing loop do
 * all the emitting — no caller of xmlToCommand() needs touching.
 */

final class SecretsmanPatchError extends \RuntimeException
{
}

const SECRETSMAN_PATCH_ANCHOR = "  foreach (\$xml['Config'] as \$key => \$config) {\n";

const SECRETSMAN_PATCH_MARKER_BEGIN = '// SECRETSMAN-PATCH-BEGIN v1 (github.com/kmbrimble/unraid-secretsman)';
const SECRETSMAN_PATCH_MARKER_END   = '// SECRETSMAN-PATCH-END';

/**
 * The injected block. $libPath is the absolute path to src/secretsman.php
 * inside the plugin's own installed directory — never copied into emhttp.
 */
function secretsman_patch_block(string $libPath): string
{
    $lib = var_export($libPath, true);
    return
        '  ' . SECRETSMAN_PATCH_MARKER_BEGIN . "\n" .
        "  if (!is_file({$lib})) {\n" .
        "    throw new \\RuntimeException('secretsman: resolver library missing at ' . {$lib} . ' — reinstall the plugin or remove the patch');\n" .
        "  }\n" .
        "  require_once {$lib};\n" .
        "  secretsman_resolve(\$xml, \$xml['Name']);\n" .
        '  ' . SECRETSMAN_PATCH_MARKER_END . "\n";
}

function secretsman_patch_is_applied(string $contents): bool
{
    return str_contains($contents, SECRETSMAN_PATCH_MARKER_BEGIN);
}

/**
 * Verify the live file's state before touching anything.
 *
 * Returns one of:
 *   ['status' => 'already-patched']                        — no-op, success
 *   ['status' => 'unpatched-known-good', 'hash' => $md5]    — safe to patch
 *   ['status' => 'mismatch', 'hash' => $md5]                — ABORT, notify
 *
 * $knownGoodHashes is the set of md5s (unpatched, stock) this patch version
 * is verified safe against — e.g. from reference/<version>/HASHES.
 */
function secretsman_patch_verify(string $filePath, array $knownGoodHashes): array
{
    if (!is_file($filePath)) {
        throw new SecretsmanPatchError("secretsman: cannot verify — {$filePath} does not exist");
    }
    $contents = file_get_contents($filePath);
    if ($contents === false) {
        throw new SecretsmanPatchError("secretsman: cannot verify — {$filePath} could not be read");
    }

    if (secretsman_patch_is_applied($contents)) {
        return ['status' => 'already-patched'];
    }

    $hash = md5($contents);
    if (in_array($hash, $knownGoodHashes, true)) {
        return ['status' => 'unpatched-known-good', 'hash' => $hash];
    }

    return ['status' => 'mismatch', 'hash' => $hash];
}

/**
 * Apply the patch to $contents (a string, not a file — the caller decides
 * whether that's a live file or a scratch copy). Throws if the anchor line
 * isn't found, or if the marker is already present (call
 * secretsman_patch_is_applied() first — this function does not silently
 * no-op, so double-injection is a bug, not a caught case).
 */
function secretsman_patch_apply(string $contents, string $libPath): string
{
    if (secretsman_patch_is_applied($contents)) {
        throw new SecretsmanPatchError('secretsman: refusing to patch — marker already present');
    }

    $pos = strpos($contents, SECRETSMAN_PATCH_ANCHOR);
    if ($pos === false) {
        throw new SecretsmanPatchError(
            'secretsman: anchor line not found — this file does not match a known-good hash ' .
            'closely enough to patch; this should have been caught by secretsman_patch_verify()'
        );
    }

    // Only one occurrence is expected and required — patching the first
    // (and only known) match. A second occurrence would mean the file
    // changed in a way the hash check should already have caught.
    if (strpos($contents, SECRETSMAN_PATCH_ANCHOR, $pos + 1) !== false) {
        throw new SecretsmanPatchError('secretsman: anchor line is not unique in this file — refusing to guess');
    }

    $block = secretsman_patch_block($libPath);
    return substr_replace($contents, $block . SECRETSMAN_PATCH_ANCHOR, $pos, strlen(SECRETSMAN_PATCH_ANCHOR));
}

/**
 * The inverse of secretsman_patch_apply(): strips the marker-delimited
 * block, returning $contents unchanged if no patch is present. Removes
 * from the start of the line containing the begin-marker through the end
 * of the line containing the end-marker, inclusive — exactly reversing
 * the insertion secretsman_patch_apply() performed.
 *
 * Throws only if the markers are present but malformed (begin without a
 * matching end, or out of order) — that should be impossible from a file
 * this library itself patched, and is a "stop, don't guess" case, not a
 * silent no-op.
 */
function secretsman_patch_revert(string $contents): string
{
    if (!secretsman_patch_is_applied($contents)) {
        return $contents;
    }

    $beginPos = strpos($contents, SECRETSMAN_PATCH_MARKER_BEGIN);
    $endPos = strpos($contents, SECRETSMAN_PATCH_MARKER_END);
    if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
        throw new SecretsmanPatchError(
            'secretsman: patch markers are present but malformed — refusing to guess'
        );
    }

    $lineStart = strrpos(substr($contents, 0, $beginPos), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $lineEnd = strpos($contents, "\n", $endPos);
    $lineEnd = $lineEnd === false ? strlen($contents) : $lineEnd + 1;

    return substr($contents, 0, $lineStart) . substr($contents, $lineEnd);
}

/**
 * The full apply-to-a-live-file flow, in one call: verify, and only patch
 * on a known-good match. Never a whole-file replacement — reads, verifies,
 * and writes back to the same path atomically (temp + rename).
 *
 * Returns the same shape as secretsman_patch_verify(), so the caller (the
 * boot script) can decide what to log / notify based on ['status'].
 */
function secretsman_patch_apply_to_file(string $filePath, string $libPath, array $knownGoodHashes): array
{
    $verify = secretsman_patch_verify($filePath, $knownGoodHashes);

    if ($verify['status'] !== 'unpatched-known-good') {
        return $verify; // already-patched (no-op) or mismatch (caller must abort + notify)
    }

    $contents = file_get_contents($filePath);
    if ($contents === false) {
        throw new SecretsmanPatchError("secretsman: {$filePath} could not be read for patching");
    }
    $patched = secretsman_patch_apply($contents, $libPath);

    $tmp = $filePath . '.secretsman-tmp' . getmypid();
    if (@file_put_contents($tmp, $patched) === false) {
        throw new SecretsmanPatchError("secretsman: could not write {$tmp}");
    }
    // Preserve the original file's mode/ownership rather than whatever
    // the temp file was created with.
    $perms = fileperms($filePath);
    if ($perms !== false) {
        @chmod($tmp, $perms & 0777);
    }
    if (!@rename($tmp, $filePath)) {
        @unlink($tmp);
        throw new SecretsmanPatchError("secretsman: could not finalize patch to {$filePath}");
    }

    return $verify;
}
