<?php
declare(strict_types=1);

/**
 * secretsman — backend for SecretsMan.page's Backup & Restore section. Same
 * shape as store_api.php: one dispatch, one renderer reused for the initial
 * paint and every mutation, JSON responses, requires only src/secretsman.php
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

/** Escape for HTML text content — every interpolated value goes through this. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function option(string $value, string $label, string $selected): string
{
    $sel = $value === $selected ? ' selected' : '';
    return '<option value="' . h($value) . '"' . $sel . '>' . h($label) . '</option>';
}

/**
 * The one renderer for this section — the config form (populated with the
 * current settings), status, and the restore controls. Never receives or
 * emits the backup password's actual value: config_get reports only
 * whether one is set, never what it is.
 */
function render_backup_html(array $config, bool $passwordSet, array $archives): string
{
    $schedule = $config['schedule'] ?? ['mode' => 'off', 'hour' => 0, 'minute' => 0, 'weekday' => 0, 'dayOfMonth' => 1];
    $mode = $schedule['mode'] ?? 'off';

    $html = '<table class="new"><tr>';
    $html .= '<td>Destination</td><td>'
        . '<input type="text" id="bk-dest" value="' . h((string)($config['destination'] ?? '')) . '" placeholder="/mnt/user/backups/secretsman"> '
        . '<button id="bk-dest-browse" type="button">Browse&hellip;</button>'
        . '<div id="bk-dest-tree" style="display:none;max-height:16em;overflow:auto;border:1px solid var(--input-border-color, #888);padding:.5em;margin-top:.5em"></div>'
        . '</td>';
    $html .= '</tr><tr>';
    $html .= '<td>Schedule</td><td>'
        . '<select id="bk-mode" class="narrow">'
        . option('off', 'Off', $mode) . option('daily', 'Daily', $mode)
        . option('weekly', 'Weekly', $mode) . option('monthly', 'Monthly', $mode)
        . '</select> '
        . '<input type="number" id="bk-hour" class="narrow" min="0" max="23" value="' . (int)($schedule['hour'] ?? 0) . '" title="Hour (0-23)"> : '
        . '<input type="number" id="bk-minute" class="narrow" min="0" max="59" value="' . (int)($schedule['minute'] ?? 0) . '" title="Minute (0-59)"> '
        . '<select id="bk-weekday" class="narrow" title="Day of week">'
        . option('0', 'Sunday', (string)($schedule['weekday'] ?? 0)) . option('1', 'Monday', (string)($schedule['weekday'] ?? 0))
        . option('2', 'Tuesday', (string)($schedule['weekday'] ?? 0)) . option('3', 'Wednesday', (string)($schedule['weekday'] ?? 0))
        . option('4', 'Thursday', (string)($schedule['weekday'] ?? 0)) . option('5', 'Friday', (string)($schedule['weekday'] ?? 0))
        . option('6', 'Saturday', (string)($schedule['weekday'] ?? 0))
        . '</select> '
        . '<input type="number" id="bk-dayofmonth" class="narrow" min="1" max="28" value="' . (int)($schedule['dayOfMonth'] ?? 1) . '" title="Day of month (1-28)">'
        . '</td></tr><tr>';
    $html .= '<td>Keep last</td><td><input type="number" id="bk-retention" class="narrow" min="0" value="' . (int)($config['retention'] ?? 3) . '"> archives (0 = unlimited)</td>';
    $html .= '</tr><tr>';
    $html .= '<td>Archive password</td><td>'
        . '<input type="password" id="bk-password" class="wide" autocomplete="new-password" placeholder="'
        . ($passwordSet ? 'set — leave blank to keep it' : 'not set yet') . '"> '
        . '<label><input type="checkbox" id="bk-password-show"> show</label>'
        . '</td></tr></table>';
    $html .= '<button id="bk-save" type="button">Save backup settings</button> ';
    $html .= '<button id="bk-now" type="button"' . ($passwordSet ? '' : ' disabled title="Set a password first"') . '>Back up now</button> ';
    $html .= '<button id="bk-download" type="button"' . (empty($archives) ? ' disabled title="No archives yet"' : '') . '>Download most recent</button>';

    $last = $config['lastRun'] ?? null;
    if ($last) {
        $when = date('Y-m-d H:i:s', (int)$last['time']);
        $status = $last['ok'] ? 'ok' : 'FAILED: ' . h((string)$last['message']);
        $html .= '<p class="notice">Last backup: ' . h($when) . ' — ' . $status . '</p>';
    } else {
        $html .= '<p class="notice">No backup has run yet.</p>';
    }

    $html .= '<h4>Restore</h4>';
    if ($archives) {
        $html .= '<select id="bk-restore-select"><option value="">— choose an existing archive —</option>';
        foreach ($archives as $a) {
            $when = date('Y-m-d H:i:s', $a['mtime']);
            $html .= '<option value="' . h($a['name']) . '">' . h($a['name']) . ' (' . h($when) . ', ' . $a['bytes'] . ' bytes)</option>';
        }
        $html .= '</select> or ';
    }
    $html .= '<input type="file" id="bk-restore-file"> ';
    $html .= '<input type="password" id="bk-restore-password" placeholder="archive password" autocomplete="off"> ';
    $html .= '<label><input type="radio" name="bk-restore-mode" value="replace" checked> Replace store entirely</label> ';
    $html .= '<label><input type="radio" name="bk-restore-mode" value="merge"> Merge (add missing only)</label> ';
    $html .= '<button id="bk-restore" type="button">Restore</button>';
    $html .= '<span id="bk-restore-err" class="errortext"></span>';

    return $html;
}

function config_payload(): array
{
    $config = secretsman_backup_config_load();
    $passwordSet = secretsman_backup_password_load() !== null;
    $archives = secretsman_backup_list_archives((string)($config['destination'] ?? ''));
    return ['ok' => true, 'html' => render_backup_html($config, $passwordSet, $archives)];
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
            respond(config_payload());

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
            respond(config_payload());

        case 'restore':
            $password = (string)($_POST['password'] ?? '');
            if ($password === '') {
                throw new SecretsmanError('secretsman: enter the archive password to restore');
            }
            $mode = (string)($_POST['mode'] ?? 'replace');

            $config = secretsman_backup_config_load();
            $destination = (string)($config['destination'] ?? '');

            if (!empty($_FILES['archive']['tmp_name']) && is_uploaded_file($_FILES['archive']['tmp_name'])) {
                $archivePath = $_FILES['archive']['tmp_name'];
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
