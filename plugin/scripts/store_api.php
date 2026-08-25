<?php
declare(strict_types=1);

/**
 * secretsman — backend for SecretsMan.page. The only PHP that touches the
 * store from the GUI, and the only HTML renderer for it: the page ships an
 * empty table container and calls action=list on load, so there is exactly
 * one render path, used identically for the initial paint and every
 * post-mutation repaint. No escaping logic is duplicated in JS.
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

/** Escape for HTML text content — every interpolated value goes through this. */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * The one renderer. $store is the full (possibly empty) secret store;
 * usage/problems come from secretsman_scan_templates(). Never receives or
 * emits a plaintext secret value — only a length, never a fragment.
 */
function render_store_html(array $store, array $scan): string
{
    ksort($store);
    $flatKeys = [];
    foreach ($store as $ns => $keys) {
        foreach (array_keys($keys) as $k) {
            $flatKeys["{$ns}/{$k}"] = true;
        }
    }

    $html = '';

    if ($store === []) {
        $html .= '<p class="notice">No secrets stored yet — add one above.</p>';
    } else {
        $html .= '<div class="TableContainer"><table class="unraid secretsman shift">';
        $html .= '<thead><tr><th>Key</th><th>Value</th><th>Used by</th><th></th></tr></thead><tbody>';
        foreach ($store as $ns => $keys) {
            ksort($keys);
            $html .= '<tr class="secretsman-ns"><td colspan="4">' . h($ns) . '</td></tr>';
            foreach ($keys as $key => $value) {
                $usedBy = $scan['usage']["{$ns}/{$key}"] ?? [];
                $usedByText = $usedBy ? h(implode(', ', $usedBy)) : '&#8212;';
                $len = strlen($value);
                $nsAttr = h($ns);
                $keyAttr = h($key);
                $html .= '<tr data-ns="' . $nsAttr . '" data-key="' . $keyAttr . '">';
                $html .= '<td>' . h($key) . '</td>';
                $html .= '<td class="sm-value" data-len="' . $len . '">'
                    . '<span class="sm-mask">' . str_repeat('&#8226;', 8) . ' (' . $len . ' chars)</span>'
                    . '</td>';
                $html .= '<td>' . $usedByText . '</td>';
                $html .= '<td class="sm-actions">'
                    . '<button class="sm-reveal" type="button">Reveal</button> '
                    . '<button class="sm-copy" type="button">Copy token</button> '
                    . '<button class="sm-edit" type="button">Edit</button> '
                    . '<button class="sm-delete" type="button">Delete</button>'
                    . '</td>';
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
    }

    if ($scan['usage']) {
        $orphans = [];
        foreach ($scan['usage'] as $token => $containers) {
            if (!isset($flatKeys[$token])) {
                $orphans[$token] = $containers;
            }
        }
        if ($orphans) {
            $html .= '<h4>Templates referencing a secret that is not in the store</h4><ul class="sm-orphans">';
            foreach ($orphans as $token => $containers) {
                [$ns, $key] = explode('/', $token, 2);
                $html .= '<li><code>!secret ' . h($token) . '</code> — used by ' . h(implode(', ', $containers))
                    . '. Creating or updating ' . (count($containers) > 1 ? 'these containers' : 'this container')
                    . ' will fail until it is added.'
                    . ' <button class="sm-add-orphan" type="button" data-ns="' . h($ns) . '" data-key="' . h($key) . '">Add</button></li>';
            }
            $html .= '</ul>';
        }
    }

    if ($scan['problems']) {
        $html .= '<h4>Templates with a token that does not parse</h4><ul class="sm-problems">';
        foreach ($scan['problems'] as $p) {
            $html .= '<li>' . h($p['container']) . ' (' . h($p['field']) . '): ' . h($p['message']) . '</li>';
        }
        $html .= '</ul>';
    }

    return $html;
}

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

    $scan = secretsman_scan_templates(TEMPLATES_DIR);
    return ['ok' => true, 'html' => render_store_html($store, $scan)];
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
}
