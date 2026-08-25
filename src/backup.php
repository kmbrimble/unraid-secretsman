<?php
declare(strict_types=1);

/**
 * secretsman backup/restore — encrypted archives of the secret store.
 *
 * Version-independent, like secretsman.php and patch.php: every Unraid-
 * specific path is a default that can be overridden (env var or a direct
 * argument), so this file is testable the same way the resolver is, and
 * carries no assumption about being invoked from a web request or cron.
 *
 * Two archive formats, chosen per-host at creation time and detected from
 * the ARCHIVE ITSELF at restore time (never from what's installed locally —
 * an archive made on one host may need restoring on a different one):
 *   - 7z (AES-256, encrypted headers) when p7zip is available. Not stock
 *     Unraid — confirmed present on the recon host only via the third-party
 *     zip_manager plugin. Better restore UX (any GUI archive tool opens it).
 *   - tar + `openssl enc -aes-256-cbc -pbkdf2` otherwise. Guaranteed present
 *     on every Unraid box (stock openssl + GNU tar). Restore needs a
 *     terminal, so the exact decrypt command is always spelled out in full,
 *     with the real iteration count filled in, in README-RESTORE.txt next
 *     to every archive.
 *
 * FAIL CLOSED, same as the resolver: every archive is verified (decrypt +
 * secretsman_load_store()) immediately after creation, before being
 * considered a successful backup, and restore never applies a merge/replace
 * until the archive has passed that same validation.
 *
 * Never logs or returns a resolved secret value. Restore results report
 * namespace/key names only (added keys, collisions) — never a value.
 */

const SECRETSMAN_BACKUP_OPENSSL_ITER = 600000;
const SECRETSMAN_BACKUP_NAME_PREFIX = 'secretsman-backup-';

/** Flash config: destination, schedule, retention, last-run status. Not sensitive. */
function secretsman_backup_config_path(): string
{
    return getenv('SECRETSMAN_BACKUP_CONFIG_PATH')
        ?: '/boot/config/plugins/unraid-secretsman/backup-config.json';
}

/**
 * The archive password. Lives beside store.json — outside flash, root:root
 * 0600 — NOT in store.json itself (that would need the store to restore the
 * store) and NOT on flash (the whole point of this plugin is that flash
 * ends up in backups; putting the one key that decrypts every secret there
 * would defeat it). This adds no new exposure: anything that can read this
 * file can already read store.json directly in the same directory.
 */
function secretsman_backup_password_path(): string
{
    return getenv('SECRETSMAN_BACKUP_PASSWORD_PATH')
        ?: dirname(secretsman_default_store_path()) . '/backup-password';
}

/** Never throws. Missing/unreadable config is "not configured yet", not an error. */
function secretsman_backup_config_load(): array
{
    $default = [
        'destination' => '',
        'schedule'    => ['mode' => 'off', 'hour' => 0, 'minute' => 0, 'weekday' => 0, 'dayOfMonth' => 1],
        'retention'   => 3, // this is an emergency-restore mechanism, not version history —
                            // 3 rather than 2 so one bad backup over a corrupted store
                            // doesn't immediately evict the last known-good archive
        'lastRun'     => null, // ['time' => int, 'ok' => bool, 'message' => string]
    ];
    $path = secretsman_backup_config_path();
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }
    return array_merge($default, $decoded);
}

function secretsman_backup_config_save(array $config): void
{
    $path = secretsman_backup_config_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new SecretsmanError("secretsman: could not create directory {$dir}");
    }
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        throw new SecretsmanError("secretsman: could not write {$tmp}");
    }
    chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new SecretsmanError("secretsman: could not finalize {$path}");
    }
}

/** Null if no password has been set yet — not an error, just "not configured". */
function secretsman_backup_password_load(): ?string
{
    $path = secretsman_backup_password_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    return $raw === false ? null : rtrim($raw, "\n");
}

function secretsman_backup_password_save(string $password): void
{
    $path = secretsman_backup_password_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new SecretsmanError("secretsman: could not create directory {$dir}");
    }
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $password) === false) {
        throw new SecretsmanError("secretsman: could not write {$tmp}");
    }
    chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new SecretsmanError("secretsman: could not finalize {$path}");
    }
}

/** Which archive format THIS host can create. Override for tests via env var. */
function secretsman_backup_tool(): string
{
    $override = getenv('SECRETSMAN_BACKUP_TOOL');
    if ($override === 'sevenzip' || $override === 'openssl') {
        return $override;
    }
    return secretsman_backup_7z_bin() !== null ? 'sevenzip' : 'openssl';
}

/** Path to a usable 7z binary, or null if none is present on this host. */
function secretsman_backup_7z_bin(): ?string
{
    $override = getenv('SECRETSMAN_BACKUP_7Z_BIN');
    if ($override !== false) {
        return is_executable($override) ? $override : null;
    }
    foreach (['/usr/bin/7zzs', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Detect an archive's format from the file's own magic bytes — never from
 * what's installed locally. An archive made where 7z was available may need
 * restoring where it isn't, and vice versa.
 */
function secretsman_backup_detect_format(string $archivePath): string
{
    $fh = @fopen($archivePath, 'rb');
    if ($fh === false) {
        throw new SecretsmanError("secretsman: could not open {$archivePath}");
    }
    $head = fread($fh, 8);
    fclose($fh);

    if (substr($head, 0, 6) === "7z\xBC\xAF\x27\x1C") {
        return 'sevenzip';
    }
    if (substr($head, 0, 8) === 'Salted__') {
        return 'openssl';
    }
    throw new SecretsmanError("secretsman: {$archivePath} is not a recognised secretsman backup archive");
}

/**
 * `openssl enc` provides confidentiality only — plain AES-256-CBC has no
 * integrity check, and this host's OpenSSL refuses AEAD ciphers outright
 * via the `enc` subcommand ("AEAD ciphers not supported", confirmed on
 * 3.0.x and 3.5.x). A bit-flip that doesn't land in the final padding block
 * or in the actual JSON content can otherwise go undetected. Rather than
 * changing the ciphertext format (which would break the plain `openssl
 * enc -d` one-liner this whole fallback path exists to keep working for
 * someone with no tooling but a terminal), an HMAC-SHA256 sidecar covers
 * the whole ciphertext, keyed from the same password via an independently
 * salted PBKDF2 derivation. Checked before every decrypt attempt when
 * present; a manual CLI decrypt can simply ignore the sidecar and lose only
 * the tamper-evidence, not the ability to recover the data. Not applied to
 * the 7z path, which already has its own per-file CRC32 checking.
 */
function secretsman_backup_openssl_salt(string $archivePath): string
{
    $fh = @fopen($archivePath, 'rb');
    if ($fh === false) {
        throw new SecretsmanError("secretsman: could not open {$archivePath}");
    }
    $head = fread($fh, 16);
    fclose($fh);
    if (substr($head, 0, 8) !== 'Salted__' || strlen($head) < 16) {
        throw new SecretsmanError("secretsman: {$archivePath} is missing the expected openssl salt header");
    }
    return substr($head, 8, 8);
}

function secretsman_backup_openssl_mac_key(string $password, string $salt): string
{
    return hash_pbkdf2('sha256', $password, $salt . ':secretsman-hmac', SECRETSMAN_BACKUP_OPENSSL_ITER, 32, true);
}

function secretsman_backup_openssl_hmac_path(string $archivePath): string
{
    return $archivePath . '.hmac';
}

/** Run a command (array form — no shell involved) and pipe $stdin to it. */
function secretsman_backup_run(array $cmd, string $stdin = '', ?string $cwd = null): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new SecretsmanError('secretsman: could not start ' . $cmd[0]);
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stdout, $stderr];
}

function secretsman_backup_readme(string $tool, string $archiveName): string
{
    if ($tool === 'sevenzip') {
        return <<<TXT
            secretsman backup — restore instructions
            =========================================

            Format: 7z (AES-256, encrypted headers)
            Archive: {$archiveName}

            To restore:
              1. Open {$archiveName} in 7-Zip, Keka, or The Unarchiver — any tool that
                 supports 7z with AES-256 encryption. On a Mac, macOS's own built-in
                 Archive Utility does NOT support this and will refuse the archive or
                 report it as damaged — that is not a sign your password is wrong, it
                 just cannot open this format. Use The Unarchiver or Keka instead.
              2. Enter the password you set in SecretsMan's backup configuration on the
                 Unraid box this came from. The password is NOT stored in or near this
                 archive.
              3. The extracted file is "store.json" — a plain JSON file,
                 {"namespace": {"key": "value", ...}, ...}. That's the whole secret
                 store, in the exact format secretsman expects.

            To restore into a running secretsman install, use the Restore section on the
            SecretsMan settings page instead of doing this by hand — it validates the
            file before touching your live store.
            TXT;
    }

    $iter = SECRETSMAN_BACKUP_OPENSSL_ITER;
    return <<<TXT
        secretsman backup — restore instructions
        =========================================

        Format: tar, encrypted with OpenSSL (AES-256-CBC, PBKDF2)
        Archive: {$archiveName}

        To restore, from a terminal:
          openssl enc -d -aes-256-cbc -pbkdf2 -iter {$iter} -in {$archiveName} | tar -x

        You will be prompted for the password you set in SecretsMan's backup
        configuration on the Unraid box this came from. The password is NOT stored in
        or near this archive. This produces a file "store.json" — a plain JSON file,
        {"namespace": {"key": "value", ...}, ...}. That's the whole secret store, in
        the exact format secretsman expects.

        To restore into a running secretsman install, use the Restore section on the
        SecretsMan settings page instead of doing this by hand — it validates the file
        before touching your live store.
        TXT;
}

/**
 * Create, then immediately verify, an encrypted archive of $storePath in
 * $destDir. Fails closed: the current store must itself be valid (refuses
 * to archive known-corrupt JSON), and the freshly-written archive must
 * decrypt and validate before this returns success — a backup that can't
 * be read back is worse than no backup.
 */
function secretsman_backup_create(string $storePath, string $password, string $destDir, ?string $tool = null): array
{
    secretsman_load_store($storePath); // fail closed: don't archive a broken store

    $tool = $tool ?? secretsman_backup_tool();
    if (!is_dir($destDir) && !@mkdir($destDir, 0700, true) && !is_dir($destDir)) {
        throw new SecretsmanError("secretsman: could not create backup destination {$destDir}");
    }

    $stamp = date('Ymd-His');
    $ext = $tool === 'sevenzip' ? '7z' : 'tar.enc';
    $archiveName = SECRETSMAN_BACKUP_NAME_PREFIX . $stamp . '.' . $ext;
    $archivePath = $destDir . '/' . $archiveName;
    $readmePath = $destDir . '/' . SECRETSMAN_BACKUP_NAME_PREFIX . $stamp . '.README-RESTORE.txt';

    $work = sys_get_temp_dir() . '/secretsman-backup-' . bin2hex(random_bytes(8));
    mkdir($work, 0700, true);
    try {
        copy($storePath, $work . '/store.json');

        if ($tool === 'sevenzip') {
            $bin = secretsman_backup_7z_bin();
            if ($bin === null) {
                throw new SecretsmanError('secretsman: 7z was requested but is not available on this host');
            }
            // cwd=$work so the archive contains only "store.json", not the
            // full temp-directory path.
            [$exit, , $stderr] = secretsman_backup_run(
                [$bin, 'a', '-t7z', '-p' . $password, '-mhe=on', '-mx=9', $archivePath, 'store.json'],
                '',
                $work,
            );
            if ($exit !== 0 || !is_file($archivePath)) {
                @unlink($archivePath);
                throw new SecretsmanError('secretsman: 7z archive creation failed: ' . trim($stderr));
            }
        } else {
            $tarPath = $work . '/store.tar';
            [$exit, , $stderr] = secretsman_backup_run(['tar', '-cf', $tarPath, '-C', $work, 'store.json']);
            if ($exit !== 0) {
                throw new SecretsmanError('secretsman: tar failed: ' . trim($stderr));
            }
            [$exit, , $stderr] = secretsman_backup_run([
                'openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-iter', (string)SECRETSMAN_BACKUP_OPENSSL_ITER,
                '-salt', '-in', $tarPath, '-out', $archivePath, '-pass', 'stdin',
            ], $password . "\n");
            @unlink($tarPath);
            if ($exit !== 0 || !is_file($archivePath)) {
                @unlink($archivePath);
                throw new SecretsmanError('secretsman: openssl encryption failed: ' . trim($stderr));
            }

            $salt = secretsman_backup_openssl_salt($archivePath);
            $macKey = secretsman_backup_openssl_mac_key($password, $salt);
            $mac = hash_hmac('sha256', file_get_contents($archivePath), $macKey);
            file_put_contents(secretsman_backup_openssl_hmac_path($archivePath), $mac);
            chmod(secretsman_backup_openssl_hmac_path($archivePath), 0600);
        }
    } finally {
        @unlink($work . '/store.json');
        @rmdir($work);
    }

    chmod($archivePath, 0600);
    file_put_contents($readmePath, secretsman_backup_readme($tool, $archiveName));
    chmod($readmePath, 0644); // deliberately world-readable: it holds no secret, and must be readable without this plugin

    // Verify: a backup that can't be read back is worse than none.
    try {
        secretsman_backup_verify($archivePath, $password);
    } catch (SecretsmanError $e) {
        @unlink($archivePath);
        @unlink($readmePath);
        @unlink(secretsman_backup_openssl_hmac_path($archivePath));
        throw new SecretsmanError('secretsman: backup created but failed verification, discarded: ' . $e->getMessage());
    }

    return [
        'path' => $archivePath,
        'readme' => $readmePath,
        'tool' => $tool,
        'bytes' => filesize($archivePath),
    ];
}

/**
 * Decrypt and validate an archive, returning the decoded store array.
 * Detects format from the file itself. Throws a specific, actionable
 * message when the tool required for THAT format isn't available here —
 * never a generic decrypt failure that leaves the cause a mystery.
 */
function secretsman_backup_verify(string $archivePath, string $password): array
{
    if (!is_file($archivePath)) {
        throw new SecretsmanError("secretsman: no such archive {$archivePath}");
    }
    $format = secretsman_backup_detect_format($archivePath);

    $work = sys_get_temp_dir() . '/secretsman-restore-' . bin2hex(random_bytes(8));
    mkdir($work, 0700, true);
    try {
        if ($format === 'sevenzip') {
            $bin = secretsman_backup_7z_bin();
            if ($bin === null) {
                throw new SecretsmanError('secretsman: this archive is 7z format; 7-Zip is not available on this host');
            }
            [$exit, , $stderr] = secretsman_backup_run([$bin, 'x', '-p' . $password, '-o' . $work, '-y', $archivePath]);
            if ($exit !== 0) {
                throw new SecretsmanError('secretsman: could not decrypt archive — wrong password, or the archive is corrupt');
            }
        } else {
            $hmacPath = secretsman_backup_openssl_hmac_path($archivePath);
            if (is_file($hmacPath)) {
                $salt = secretsman_backup_openssl_salt($archivePath);
                $macKey = secretsman_backup_openssl_mac_key($password, $salt);
                $expected = hash_hmac('sha256', file_get_contents($archivePath), $macKey);
                $actual = trim((string)file_get_contents($hmacPath));
                if (!hash_equals($expected, $actual)) {
                    throw new SecretsmanError(
                        'secretsman: archive integrity check failed — wrong password, or the file is corrupted or has been tampered with'
                    );
                }
            }

            $tarPath = $work . '/store.tar';
            [$exit, , $stderr] = secretsman_backup_run([
                'openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', (string)SECRETSMAN_BACKUP_OPENSSL_ITER,
                '-in', $archivePath, '-out', $tarPath, '-pass', 'stdin',
            ], $password . "\n");
            if ($exit !== 0 || !is_file($tarPath)) {
                throw new SecretsmanError('secretsman: could not decrypt archive — wrong password, or the archive is corrupt');
            }
            [$exit, , $stderr] = secretsman_backup_run(['tar', '-xf', $tarPath, '-C', $work]);
            if ($exit !== 0) {
                throw new SecretsmanError('secretsman: archive decrypted but could not be extracted — corrupt archive');
            }
        }

        $extracted = $work . '/store.json';
        if (!is_file($extracted)) {
            throw new SecretsmanError('secretsman: archive did not contain a store.json');
        }
        chmod($extracted, 0600); // secretsman_load_store() requires exactly 0600
        clearstatcache(true, $extracted); // is_file() above cached the pre-chmod mode
        return secretsman_load_store($extracted);
    } finally {
        @unlink($work . '/store.json');
        @unlink($work . '/store.tar');
        @rmdir($work);
    }
}

/**
 * Restore an archive into $storePath.
 *   'replace' — the archive becomes the store, in full.
 *   'merge'   — keys not already present are added; keys present in both
 *               are left untouched and reported as collisions (names only,
 *               never values) rather than silently resolved either way.
 */
function secretsman_backup_restore(string $archivePath, string $password, string $storePath, string $mode = 'replace'): array
{
    $incoming = secretsman_backup_verify($archivePath, $password);

    if ($mode === 'replace') {
        secretsman_save_store($storePath, $incoming);
        $added = [];
        foreach ($incoming as $ns => $keys) {
            foreach (array_keys($keys) as $k) {
                $added[] = "{$ns}/{$k}";
            }
        }
        return ['mode' => 'replace', 'added' => $added, 'collisions' => []];
    }

    if ($mode !== 'merge') {
        throw new SecretsmanError("secretsman: unknown restore mode '{$mode}'");
    }

    $current = is_file($storePath) ? secretsman_load_store($storePath) : [];
    $added = [];
    $collisions = [];
    foreach ($incoming as $ns => $keys) {
        foreach ($keys as $k => $v) {
            if (isset($current[$ns][$k])) {
                $collisions[] = "{$ns}/{$k}";
                continue;
            }
            $current[$ns][$k] = $v;
            $added[] = "{$ns}/{$k}";
        }
    }
    secretsman_save_store($storePath, $current);
    return ['mode' => 'merge', 'added' => $added, 'collisions' => $collisions];
}

/** Every secretsman archive in $destDir, newest first. */
function secretsman_backup_list_archives(string $destDir): array
{
    $out = [];
    foreach (glob($destDir . '/' . SECRETSMAN_BACKUP_NAME_PREFIX . '*.{7z,tar.enc}', GLOB_BRACE) ?: [] as $path) {
        $out[] = ['path' => $path, 'name' => basename($path), 'mtime' => filemtime($path), 'bytes' => filesize($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/**
 * Keep the newest $keep archives (and their matching README), delete the
 * rest. $keep <= 0 means unlimited (nothing pruned). Returns the basenames
 * deleted, for the caller to log/report.
 */
function secretsman_backup_prune(string $destDir, int $keep): array
{
    if ($keep <= 0) {
        return [];
    }
    $archives = secretsman_backup_list_archives($destDir);
    $toDelete = array_slice($archives, $keep);
    $deleted = [];
    foreach ($toDelete as $a) {
        @unlink($a['path']);
        @unlink(secretsman_backup_openssl_hmac_path($a['path']));
        $readme = preg_replace('/\.(7z|tar\.enc)$/', '.README-RESTORE.txt', $a['path']);
        if ($readme !== null) {
            @unlink($readme);
        }
        $deleted[] = $a['name'];
    }
    return $deleted;
}

/**
 * Pure: build the 5-field cron line (no leading comment, no trailing
 * newline) for a schedule, or null if the schedule is off. Kept side-effect
 * free and separate from plugin/scripts/backup_cron_register.php's file
 * I/O + update_cron call, so it's directly unit-testable.
 */
function secretsman_backup_cron_line(array $schedule, string $scriptPath): ?string
{
    $mode = $schedule['mode'] ?? 'off';
    $hour = (int)($schedule['hour'] ?? 0);
    $minute = (int)($schedule['minute'] ?? 0);
    $weekday = (int)($schedule['weekday'] ?? 0);
    $dayOfMonth = (int)($schedule['dayOfMonth'] ?? 1);

    $fields = match ($mode) {
        'daily' => "{$minute} {$hour} * * *",
        'weekly' => "{$minute} {$hour} * * {$weekday}",
        'monthly' => "{$minute} {$hour} {$dayOfMonth} * *",
        default => null,
    };
    if ($fields === null) {
        return null;
    }
    return "{$fields} php {$scriptPath} > /dev/null 2>&1";
}
