<?php
declare(strict_types=1);

/**
 * secretsman — JSON backend for the SecretsMan web app (plugin/app/). The only
 * PHP that touches the store from the GUI. It renders nothing: `list` and
 * every mutation return the same JSON payload (see list_payload()), and the
 * Preact app builds the DOM, escaping through the framework.
 *
 * Deliberately requires ONLY src/secretsman.php, not common.php:
 * secretsman_notify() has no reason to fire here (a GUI error goes to the
 * user who caused it, in the page, not to an Unraid notification), and its
 * STDERR fallback would fatal under php-fpm anyway (STDERR is a CLI-SAPI-only
 * constant — see the known-issue note in CLAUDE.md).
 *
 * No CSRF check here, deliberately: webGui/include/local_prepend.php (the
 * global auto_prepend_file) already enforces CSRF on every POST reaching any
 * plugin PHP file, and CONSUMES the token (unset($_POST['csrf_token'])) once
 * it validates. A second check here would read an already-emptied field and
 * fail on every legitimate request — this is not a hypothetical, it's what
 * shipped first and had to be reverted. See CLAUDE.md's standing note on
 * this exact class of mistake (defensive redundancy on a mechanism Unraid
 * already guarantees).
 */

require_once __DIR__ . '/../src/secretsman.php';

header('Content-Type: application/json');
header('Cache-Control: no-store'); // a reveal response carries a plaintext value

const TEMPLATES_DIR = '/boot/config/plugins/dockerMan/templates-user';

function respond(array $body): void
{
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    respond(['ok' => false, 'error' => 'secretsman: POST required']);
}

/**
 * The list payload: namespaces -> keys -> value LENGTH and the containers whose saved templates reference it, plus
 * tokens templates use that are not in the store (orphans) and tokens that do not parse (problems). Never a value,
 * never a fragment of one: the only response that carries a value is `reveal`, for one named key.
 */
function list_payload(string $storePath): array
{
    if (!is_file($storePath)) {
        $store = [];
    } else {
        try {
            $store = secretsman_load_store($storePath);
        } catch (SecretsmanError $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    $store = secretsman_sort_store($store);
    $scan = secretsman_scan_templates(templates_dir());

    $namespaces = [];
    $known = [];
    foreach ($store as $ns => $keys) {
        $rows = [];
        foreach ($keys as $key => $value) {
            $known["{$ns}/{$key}"] = true;
            $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            $rows[] = ['key' => (string)$key, 'length' => $len, 'usedBy' => array_values($scan['usage']["{$ns}/{$key}"] ?? [])];
        }
        $namespaces[] = ['name' => (string)$ns, 'keys' => $rows];
    }

    $orphans = [];
    foreach ($scan['usage'] as $token => $containers) {
        if (!isset($known[$token])) {
            [$ns, $key] = explode('/', $token, 2);
            $orphans[] = ['token' => $token, 'ns' => $ns, 'key' => $key, 'usedBy' => array_values($containers)];
        }
    }

    return ['ok' => true, 'namespaces' => $namespaces, 'orphans' => $orphans, 'problems' => $scan['problems']];
}

function templates_dir(): string
{
    $override = getenv('SECRETSMAN_TEMPLATES_DIR');
    return $override !== false && $override !== '' ? $override : TEMPLATES_DIR;
}

$storePath = secretsman_default_store_path();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            respond(list_payload($storePath));

        case 'add':
            secretsman_store_set(
                $storePath,
                (string)($_POST['ns'] ?? ''),
                (string)($_POST['key'] ?? ''),
                (string)($_POST['value'] ?? ''),
                false
            );
            respond(list_payload($storePath));

        case 'edit':
            secretsman_store_set(
                $storePath,
                (string)($_POST['ns'] ?? ''),
                (string)($_POST['key'] ?? ''),
                (string)($_POST['value'] ?? ''),
                true
            );
            respond(list_payload($storePath));

        case 'delete':
            secretsman_store_delete($storePath, (string)($_POST['ns'] ?? ''), (string)($_POST['key'] ?? ''));
            respond(list_payload($storePath));

        case 'reveal':
            $store = secretsman_load_store($storePath);
            $value = secretsman_lookup($store, (string)($_POST['ns'] ?? ''), (string)($_POST['key'] ?? ''));
            respond(['ok' => true, 'value' => $value]);

        default:
            http_response_code(400);
            respond(['ok' => false, 'error' => 'secretsman: unknown action']);
    }
} catch (SecretsmanError $e) {
    // Every SecretsmanError message names the shape of the problem, never a
    // value (project rule 3) — safe to return to the client verbatim.
    respond(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // See backup_api.php's identical catch for why: an uncaught fatal
    // otherwise reaches the client as a bare 500 with no body, which the
    // page can only report as "check the browser console" — a dead end
    // this project has already been burned by once (the blank Docker Apply
    // page) and isn't repeating here too.
    error_log('secretsman: unhandled ' . get_class($e) . ' in store_api.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    respond(['ok' => false, 'error' => 'secretsman: internal error — ' . get_class($e) . ': ' . $e->getMessage()]);
}
