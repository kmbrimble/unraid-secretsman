<?php
declare(strict_types=1);

/**
 * secretsman web app shell. A tiny PHP page, served by the webGUI's own nginx/php-fpm, so it inherits the webGUI
 * session (nginx auth_request) with no daemon, port or login of its own. It does three things:
 *
 *   1. emits the CSRF token the app needs for its POSTs. The webGUI's local_prepend.php validates it (and then
 *      consumes it); nothing here checks it again. Only csrf_token is read from var.ini, nothing else from it;
 *   2. sets data-theme (light/dark) from the Unraid theme, so the app matches the webGUI around it: black and gray
 *      are the dark themes, white and azure the light ones (ThemeHelper.php in dynamix);
 *   3. loads the built, content-versioned JS and CSS from ./assets/.
 *
 * It is reached both inside the SecretsMan tab's iframe and standalone at the same URL. GET only, no store access.
 * Rule 8: nothing here uses STDOUT/STDERR/$argv; failures degrade to an empty token / light theme, never a fatal.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store'); // the page carries the session's CSRF token

function sm_env(string $name, string $default): string
{
    $v = getenv($name);
    return $v !== false && $v !== '' ? $v : $default;
}

function sm_csrf_token(): string
{
    $path = sm_env('SECRETSMAN_VAR_INI', '/var/local/emhttp/var.ini');
    $ini = is_file($path) ? @parse_ini_file($path) : false;
    $token = is_array($ini) ? ($ini['csrf_token'] ?? '') : '';
    return is_string($token) ? $token : '';
}

function sm_theme(): string
{
    $path = sm_env('SECRETSMAN_DYNAMIX_CFG', '/boot/config/plugins/dynamix/dynamix.cfg');
    $cfg = is_file($path) ? @parse_ini_file($path, true) : false;
    $name = is_array($cfg) ? (string)($cfg['display']['theme'] ?? '') : '';
    return in_array($name, ['black', 'gray'], true) ? 'dark' : 'light';
}

function sm_asset(string $file): string
{
    $path = __DIR__ . '/assets/' . $file;
    $v = is_file($path) ? substr((string)md5_file($path), 0, 12) : '0';
    return 'assets/' . $file . '?v=' . $v;
}

function sm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

?><!doctype html>
<html lang="en" data-theme="<?= sm_h(sm_theme()) ?>" data-server-theme="<?= sm_h(sm_theme()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>SecretsMan</title>
<script>
/* Runs before the stylesheet, so a stored light/dark choice is applied before first paint (no flash). "auto" (or any
   other value, or no storage at all) leaves the server-chosen theme from the Unraid setting in place. */
try{var t=localStorage.getItem('secretsman-theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}
</script>
<link rel="stylesheet" href="<?= sm_h(sm_asset('app.css')) ?>">
</head>
<body>
<div id="app" data-csrf="<?= sm_h(sm_csrf_token()) ?>"><p class="sm-boot">Loading SecretsMan&hellip;</p></div>
<noscript><p class="sm-boot">SecretsMan needs JavaScript.</p></noscript>
<script type="module" src="<?= sm_h(sm_asset('app.js')) ?>"></script>
</body>
</html>
