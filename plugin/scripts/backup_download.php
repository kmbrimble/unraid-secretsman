<?php
declare(strict_types=1);

/**
 * Streams the most recent backup archive. Deliberately NOT
 * webGui/include/Download.php's pattern (copy into the web-servable
 * webroot, then link to it) — that would mean staging an encrypted secrets
 * archive somewhere world-servable and hoping the cleanup call runs. This
 * follows ca.mover.tuning/debug.php's pattern instead: stream directly with
 * Content-Disposition, no intermediate copy, nothing written to the webroot.
 *
 * GET, not POST — a download is inherently a simple navigation
 * (window.location), and there's nothing here CSRF needs to protect: it
 * only reads an already-existing archive, it doesn't mutate anything.
 */

require_once __DIR__ . '/../src/secretsman.php';
require_once __DIR__ . '/../src/backup.php';

try {
    $config = secretsman_backup_config_load();
    $destination = (string)($config['destination'] ?? '');
    $archives = $destination === '' ? [] : secretsman_backup_list_archives($destination);

    if (!$archives) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "secretsman: no backup archives found\n";
        exit;
    }

    $latest = $archives[0]['path'];
    http_response_code(200);
    header('Content-Disposition: attachment; filename="' . basename($latest) . '"');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($latest));
    header('Cache-Control: no-store');
    readfile($latest);
    exit;
} catch (\Throwable $e) {
    // Same reasoning as backup_api.php/store_api.php's catch(\Throwable):
    // a bare fatal here would otherwise be an unexplained failed download
    // with nothing in the browser to go on.
    error_log('secretsman: unhandled ' . get_class($e) . ' in backup_download.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'secretsman: internal error — ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit;
}
