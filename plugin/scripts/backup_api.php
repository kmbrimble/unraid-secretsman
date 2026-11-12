<?php
declare(strict_types=1);

/**
 * secretsman — JSON backend for the web app's Backup & Restore section. Same
 * shape as store_api.php: one dispatch, one JSON payload (config_payload())
 * for the initial load and every mutation, no HTML rendering, requires only src/secretsman.php
 * + src/backup.php (never common.php — see store_api.php's header comment;
 * the same php-fpm/STDERR reasoning applies here), and deliberately NO CSRF
 * check of its own (see store_api.php's header comment and CLAUDE.md's
 * standing note — Unraid's own auto_prepend_file already enforces this and
 * consumes the token; a second check here would repeat the exact bug that
 * shipped once already).
 */

require_once __DIR__ . '/../src/secretsman.php';
require_once __DIR__ . '/../src/backup.php';

header('Content-Type: application/json');
header('Cache-Control: no-store'); // config_get reports whether a password is set; restore results name keys

function respond(array $body): void
{
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    respond(['ok' => false, 'error' => 'secretsman: POST required']);
}

// PHP silently empties $_POST (no warning reaching this script) when a POST
// body exceeds post_max_size — without this check that looks like "unknown
// action" instead of naming the actual problem.
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    respond(['ok' => false, 'error' => 'secretsman: upload too large — this server accepts POST bodies up to ' . ini_get('post_max_size') . ' (post_max_size); try a smaller archive']);
}

/**
 * Backup state for the app: settings, whether an archive password is set (never the password), the archives found
 * at the destination (name, mtime, size: never a path), and the last run. Timestamps are epoch seconds; the
 * browser formats them.
 */
function config_payload(): array
{
    $config = secretsman_backup_config_load();
    $passwordSet = secretsman_backup_password_load() !== null;
    $archives = secretsman_backup_list_archives((string)($config['destination'] ?? ''));
    $schedule = $config['schedule'] ?? [];
    $last = $config['lastRun'] ?? null;
    return [
        'ok' => true,
        'config' => [
            'destination' => (string)($config['destination'] ?? ''),
            'schedule' => [
                'mode' => (string)($schedule['mode'] ?? 'off'),
                'hour' => (int)($schedule['hour'] ?? 0),
                'minute' => (int)($schedule['minute'] ?? 0),
                'weekday' => (int)($schedule['weekday'] ?? 0),
                'dayOfMonth' => (int)($schedule['dayOfMonth'] ?? 1),
            ],
            'retention' => (int)($config['retention'] ?? 3),
        ],
        'passwordSet' => $passwordSet,
        'archives' => array_map(
            fn($a) => ['name' => $a['name'], 'mtime' => (int)$a['mtime'], 'bytes' => (int)$a['bytes']],
            $archives
        ),
        'lastRun' => $last ? ['time' => (int)$last['time'], 'ok' => (bool)$last['ok'], 'message' => (string)$last['message']] : null,
        'uploadLimitBytes' => secretsman_backup_upload_limit_bytes(),
    ];
}

/**
 * Regenerate the cron entry in-process, same effect as running the CLI
 * script. Does not fail the whole config_set request if this specific step
 * fails — the config itself already saved — but does not swallow the
 * failure silently either: logged via error_log() (safe under php-fpm,
 * unlike fwrite(STDERR,...) — see backup_cron_register.php's own comment).
 */
function reregister_cron(): void
{
    require_once __DIR__ . '/backup_cron_register.php';
    $result = backup_cron_register_main();
    if (!$result['ok']) {
        error_log($result['message']);
    }
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            respond(config_payload());

        case 'config_set':
            $config = secretsman_backup_config_load();
            $config['destination'] = trim((string)($_POST['destination'] ?? ''));
            $config['schedule'] = [
                'mode' => (string)($_POST['mode'] ?? 'off'),
                'hour' => (int)($_POST['hour'] ?? 0),
                'minute' => (int)($_POST['minute'] ?? 0),
                'weekday' => (int)($_POST['weekday'] ?? 0),
                'dayOfMonth' => (int)($_POST['dayOfMonth'] ?? 1),
            ];
            $config['retention'] = (int)($_POST['retention'] ?? 3);
            secretsman_backup_config_save($config);

            $newPassword = (string)($_POST['password'] ?? '');
            if ($newPassword !== '') {
                secretsman_backup_password_save($newPassword);
            }

            reregister_cron();
            $payload = config_payload();
            $payload['message'] = 'secretsman: backup settings saved';
            respond($payload);

        case 'backup_now':
            // Delegates to backup_cron.php's backup_cron_main() rather than
            // duplicating its steps here — the two used to be separate
            // copies of the same logic, which is exactly how
            // backup_cron_register.php's STDOUT/STDERR bug would have
            // resurfaced the moment someone "cleaned up" the duplication by
            // wiring this the other, unsafe way. See CLAUDE.md rule 8.
            require_once __DIR__ . '/backup_cron.php';
            $result = backup_cron_main();
            if (!$result['ok']) {
                throw new SecretsmanError($result['message']);
            }
            $payload = config_payload();
            $payload['message'] = $result['message']; // names the archive path and byte count
            respond($payload);

        case 'restore':
            $password = (string)($_POST['password'] ?? '');
            if ($password === '') {
                throw new SecretsmanError('secretsman: enter the archive password to restore');
            }
            $mode = (string)($_POST['mode'] ?? 'replace');

            $config = secretsman_backup_config_load();
            $destination = (string)($config['destination'] ?? '');

            // Uploads travel as a base64 POST field, not multipart/form-data
            // — this host's nginx auth_request subrequest times out on
            // multipart POST regardless of target script or session state
            // (confirmed live, see CLAUDE.md), so plain urlencoded POST via
            // the same $.post path every other action already uses is the
            // only proven-working transport here.
            $uploadedTmpPath = null;
            try {
                if (!empty($_POST['archive_b64'])) {
                    $raw = base64_decode((string) $_POST['archive_b64'], true);
                    if ($raw === false) {
                        throw new SecretsmanError('secretsman: uploaded archive is not valid base64');
                    }
                    $uploadedTmpPath = tempnam(sys_get_temp_dir(), 'sm-restore-');
                    file_put_contents($uploadedTmpPath, $raw);
                    chmod($uploadedTmpPath, 0600);
                    $archivePath = $uploadedTmpPath;
                } elseif (!empty($_POST['selected'])) {
                    // Pin strictly to the configured destination directory and a
                    // plain basename — never trust a client-supplied path.
                    $selected = basename((string)$_POST['selected']);
                    $archivePath = $destination . '/' . $selected;
                    if ($destination === '' || !is_file($archivePath) || dirname(realpath($archivePath) ?: '') !== realpath($destination)) {
                        throw new SecretsmanError('secretsman: no such archive');
                    }
                } else {
                    throw new SecretsmanError('secretsman: choose an archive to restore, or upload one');
                }

                $result = secretsman_backup_restore($archivePath, $password, secretsman_default_store_path(), $mode);
            } finally {
                if ($uploadedTmpPath !== null) {
                    @unlink($uploadedTmpPath);
                }
            }
            $payload = config_payload();
            $payload['restored'] = $result; // names only (added/collisions), never values
            respond($payload);

        default:
            http_response_code(400);
            respond(['ok' => false, 'error' => 'secretsman: unknown action']);
    }
} catch (SecretsmanError $e) {
    // Every SecretsmanError message names the shape of the problem, never a
    // value (project rule 3) — safe to return to the client verbatim.
    respond(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Catches anything SecretsmanError doesn't — a plain PHP fatal (a typo'd
    // constant, a TypeError, ...) previously reached the client as a bare
    // 500 with no body, which jQuery could only report as "request failed —
    // check the browser console". That's exactly the failure this project
    // has already been burned by once (the blank Docker Apply page) and
    // isn't repeating a second time. Full detail goes to the PHP error log
    // (safe — never reaches the client); a readable summary goes to the
    // banner, since knowing WHAT broke beats a dead end.
    error_log('secretsman: unhandled ' . get_class($e) . ' in backup_api.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['ok' => false, 'error' => 'secretsman: internal error — ' . get_class($e) . ': ' . $e->getMessage()]);
}
