<?php
declare(strict_types=1);

/**
 * Shared helpers for the plugin's boot/CLI scripts (apply_patch.php,
 * repopulate.php, force_start.php). Not part of src/ — these are Unraid
 * environment glue (notify, template scanning), not version-independent
 * resolver logic.
 */

const SECRETSMAN_NOTIFY_BIN = '/usr/local/emhttp/webGui/scripts/notify';
const SECRETSMAN_TEMPLATES_DIR = '/boot/config/plugins/dockerMan/templates-user';
const SECRETSMAN_LIVE_HELPERS_PATH = '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

function secretsman_notify(string $subject, string $description, string $importance = 'warning', string $link = '/Docker'): void
{
    if (!is_executable(SECRETSMAN_NOTIFY_BIN)) {
        fwrite(STDERR, "secretsman: notify script not found, would have said: {$subject}: {$description}\n");
        return;
    }
    $cmd = sprintf(
        '%s -e %s -s %s -d %s -i %s -l %s >/dev/null 2>&1',
        escapeshellarg(SECRETSMAN_NOTIFY_BIN),
        escapeshellarg('secretsman'),
        escapeshellarg($subject),
        escapeshellarg($description),
        escapeshellarg($importance),
        escapeshellarg($link)
    );
    exec($cmd);
}

/** !secretfile tokens (ns, key) found in a container's saved template, [] if none/unreadable. */
function secretsman_find_file_tokens(string $templatePath): array
{
    $xmlString = @file_get_contents($templatePath);
    if ($xmlString === false) {
        return [];
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    libxml_use_internal_errors($prev);
    if ($xml === false || !isset($xml->Config)) {
        return [];
    }

    $tokens = [];
    foreach ($xml->Config as $config) {
        $type = strtolower((string)($config['Type'] ?? ''));
        if ($type !== 'variable') {
            continue;
        }
        $value = (string)$config;
        $rawValue = strlen($value) ? $value : (string)($config['Default'] ?? '');
        try {
            $parsed = secretsman_parse_token($rawValue);
        } catch (SecretsmanError $e) {
            // A malformed token in an already-saved template shouldn't be
            // possible (creation would have aborted first) — skip it here
            // rather than aborting the whole scan for one bad entry.
            continue;
        }
        if ($parsed['kind'] === 'token' && $parsed['mode'] === 'file') {
            $tokens[] = ['ns' => $parsed['ns'], 'key' => $parsed['key']];
        }
    }
    return $tokens;
}

function secretsman_template_path_for(string $containerName): string
{
    return SECRETSMAN_TEMPLATES_DIR . '/my-' . $containerName . '.xml';
}
