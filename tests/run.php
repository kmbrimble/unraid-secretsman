<?php
declare(strict_types=1);

// Plain-PHP assert runner. No framework, no Composer: `php tests/run.php`,
// identical locally and in CI.

require __DIR__ . '/../src/secretsman.php';
require __DIR__ . '/../src/patch.php';
require __DIR__ . '/../plugin/scripts/common.php';
require __DIR__ . '/../plugin/scripts/apply_patch.php';
require __DIR__ . '/../plugin/scripts/uninstall.php';

$failures = [];
$passed   = 0;

function t(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        $fn();
        $passed++;
    } catch (\Throwable $e) {
        $failures[] = sprintf("%s\n    %s: %s", $name, get_class($e), $e->getMessage());
    }
}

function assert_true($cond, string $msg = 'expected true'): void
{
    if (!$cond) throw new \Exception($msg);
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception(sprintf(
            "%sexpected %s, got %s",
            $msg ? "$msg: " : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_throws(callable $fn, string $expectedClass = SecretsmanError::class, ?string $messageContains = null): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new \Exception("expected $expectedClass, got " . get_class($e) . ": " . $e->getMessage());
        }
        if ($messageContains !== null && strpos($e->getMessage(), $messageContains) === false) {
            throw new \Exception("expected message to contain '$messageContains', got: " . $e->getMessage());
        }
        return;
    }
    throw new \Exception("expected $expectedClass to be thrown, nothing was");
}

/** Fresh scratch dir per test, cleaned up by the OS temp reaper, not us. */
function scratch_dir(): string
{
    $dir = sys_get_temp_dir() . '/secretsman-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);
    return $dir;
}

function write_store(string $dir, array $data, int $mode = 0600): string
{
    $path = $dir . '/store.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    chmod($path, $mode);
    return $path;
}

function variable_config(string $target, string $value): array
{
    return [
        'Name' => $target, 'Target' => $target, 'Default' => '', 'Value' => $value,
        'Mode' => '', 'Description' => '', 'Type' => 'Variable', 'Display' => 'always',
        'Required' => 'false', 'Mask' => 'false',
    ];
}

function path_config(string $target, string $value, string $mode = 'rw'): array
{
    return [
        'Name' => $target, 'Target' => $target, 'Default' => '', 'Value' => $value,
        'Mode' => $mode, 'Description' => '', 'Type' => 'Path', 'Display' => 'always',
        'Required' => 'true', 'Mask' => 'false',
    ];
}

// ---------------------------------------------------------------------------
// Token parsing
// ---------------------------------------------------------------------------

t('parse: valid !secret', function () {
    $p = secretsman_parse_token('!secret terriblebutler/anthropic_api_key');
    assert_eq('token', $p['kind']);
    assert_eq('env', $p['mode']);
    assert_eq('terriblebutler', $p['ns']);
    assert_eq('anthropic_api_key', $p['key']);
});

t('parse: !secretfile aborts — mode removed, points at !secret', function () {
    assert_throws(
        fn() => secretsman_parse_token('!secretfile media/plex_token'),
        SecretsmanError::class,
        '!secretfile'
    );
    // and it names the replacement, not just the removal
    try {
        secretsman_parse_token('!secretfile media/plex_token');
        throw new \Exception('expected failure');
    } catch (SecretsmanError $e) {
        assert_true(strpos($e->getMessage(), '!secret') !== false, 'error should point at !secret');
    }
});

t('parse: leading/trailing whitespace tolerated', function () {
    $p = secretsman_parse_token("  !secret ns/key  \n");
    assert_eq('token', $p['kind']);
    assert_eq('ns', $p['ns']);
    assert_eq('key', $p['key']);
});

t('parse: escaped literal strips one backslash, not resolved', function () {
    $p = secretsman_parse_token('\\!secret ns/key');
    assert_eq('literal', $p['kind']);
    assert_eq('!secret ns/key', $p['value']);
});

t('parse: escaped secretfile spelling is still just literal text (mode is gone, escaping still works)', function () {
    $p = secretsman_parse_token('\\!secretfile ns/key');
    assert_eq('literal', $p['kind']);
    assert_eq('!secretfile ns/key', $p['value']);
});

t('parse: plain value passes through untouched', function () {
    $p = secretsman_parse_token('just a normal value');
    assert_eq('none', $p['kind']);
});

t('parse: empty value passes through', function () {
    $p = secretsman_parse_token('');
    assert_eq('none', $p['kind']);
});

t('parse: value merely containing the word "secret" is not a token', function () {
    $p = secretsman_parse_token('my-secret-value');
    assert_eq('none', $p['kind']);
});

t('parse: bare "!" is not a token', function () {
    $p = secretsman_parse_token('!');
    assert_eq('none', $p['kind']);
});

t('parse: embedded token is rejected, not partially resolved', function () {
    assert_throws(fn() => secretsman_parse_token('prefix !secret ns/key'));
});

t('parse: embedded token with no separating space is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('prefix!secret ns/key'));
});

t('parse: wrong case is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!Secret ns/key'));
});

t('parse: missing slash is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!secret foo'));
});

t('parse: too many slashes is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!secret foo/bar/baz'));
});

t('parse: empty namespace is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!secret /bar'));
});

t('parse: empty key is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!secret foo/'));
});

t('parse: illegal charset is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!secret fo o/bar'));
});

t('parse: !secretfoo (no matching keyword) is rejected, not passed through', function () {
    assert_throws(fn() => secretsman_parse_token('!secretfoo ns/key'));
});

t('parse: !secret with no ns/key at all is rejected', function () {
    assert_throws(fn() => secretsman_parse_token('!secret'));
});

// ---------------------------------------------------------------------------
// Store loading
// ---------------------------------------------------------------------------

t('store: missing file aborts', function () {
    assert_throws(fn() => secretsman_load_store('/nonexistent/store.json'));
});

t('store: unreadable file aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']]);
    chmod($path, 0000);
    try {
        assert_throws(fn() => secretsman_load_store($path));
    } finally {
        chmod($path, 0600); // so cleanup/reruns don't choke
    }
});

t('store: invalid JSON aborts', function () {
    $dir = scratch_dir();
    $path = $dir . '/store.json';
    file_put_contents($path, '{not json');
    chmod($path, 0600);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: top level not an object aborts', function () {
    $dir = scratch_dir();
    $path = $dir . '/store.json';
    file_put_contents($path, '"just a string"');
    chmod($path, 0600);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: namespace not an object aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => 'not-an-object']);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: non-string value (int) aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 5]]);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: non-string value (null) aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => null]]);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: non-string value (nested array) aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => ['x' => 'y']]]);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: world-readable file (not 0600) is rejected', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']], 0644);
    assert_throws(fn() => secretsman_load_store($path));
});

t('store: newline-bearing value is rejected with a clear error', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ["k" => "line1\nline2"]]);
    assert_throws(fn() => secretsman_load_store($path), SecretsmanError::class, 'newline');
});

t('store: empty-string value is permitted', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => '']]);
    $store = secretsman_load_store($path);
    assert_eq('', $store['ns']['k']);
});

t('store: lookup missing namespace aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']]);
    $store = secretsman_load_store($path);
    assert_throws(fn() => secretsman_lookup($store, 'other', 'k'));
});

t('store: lookup missing key aborts', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']]);
    $store = secretsman_load_store($path);
    assert_throws(fn() => secretsman_lookup($store, 'ns', 'missing'));
});

t('store: lookup returns the value', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $store = secretsman_load_store($path);
    assert_eq('test-value-not-a-real-secret', secretsman_lookup($store, 'ns', 'k'));
});

// ---------------------------------------------------------------------------
// Field scope: tokens only allowed in Variable fields
// ---------------------------------------------------------------------------

function scoped_resolve_test(string $type, array $extra = []): callable
{
    return function () use ($type, $extra) {
        $dir = scratch_dir();
        $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
        $config = array_merge(
            ['Name' => 'x', 'Target' => 'x', 'Default' => '', 'Value' => '!secret ns/k',
             'Mode' => '', 'Description' => '', 'Type' => $type, 'Display' => 'always',
             'Required' => 'false', 'Mask' => 'false'],
            $extra
        );
        $xml = ['Config' => [$config], 'ExtraParams' => ''];
        assert_throws(
            fn() => secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']),
            SecretsmanError::class,
            $type
        );
    };
}

t('scope: token in Path field aborts naming the field type', scoped_resolve_test('Path'));
t('scope: token in Port field aborts naming the field type', scoped_resolve_test('Port'));
t('scope: token in Label field aborts naming the field type', scoped_resolve_test('Label'));
t('scope: token in Device field aborts naming the field type', scoped_resolve_test('Device'));

t('scope: token in ExtraParams (free text, not a Config entry) aborts', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [], 'ExtraParams' => '!secret ns/k'];
    assert_throws(
        fn() => secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']),
        SecretsmanError::class,
        'ExtraParams'
    );
});

t('scope: token in PostArgs aborts', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [], 'ExtraParams' => '', 'PostArgs' => '!secret ns/k'];
    assert_throws(
        fn() => secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']),
        SecretsmanError::class,
        'PostArgs'
    );
});

t('scope: a !secretfile token aborts the whole resolve, not just the parser', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [variable_config('MY_TOKEN', '!secretfile ns/k')], 'ExtraParams' => ''];
    assert_throws(
        fn() => secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']),
        SecretsmanError::class,
        '!secretfile'
    );
});

t('scope: a Variable token resolves without error', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [variable_config('MY_VAR', '!secret ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
    assert_true(strpos($xml['ExtraParams'], '--env-file=') !== false);
});

// ---------------------------------------------------------------------------
// Materialisation
// ---------------------------------------------------------------------------

t('materialise: env-file contains VAR=value and is 0400', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [variable_config('MY_VAR', '!secret ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);

    preg_match('/--env-file=(\S+)/', $xml['ExtraParams'], $m);
    $envFile = trim($m[1], "'");
    assert_true(is_file($envFile), 'env-file should exist');
    assert_eq("MY_VAR=test-value-not-a-real-secret\n", file_get_contents($envFile));
    assert_eq('0400', substr(sprintf('%o', fileperms($envFile)), -4));
});

t('materialise: the resolved env entry is removed from Config, no -e emitted', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [variable_config('MY_VAR', '!secret ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
    assert_eq(0, count($xml['Config']));
});

t('materialise: sweeper deletes stale env files, leaves fresh ones alone', function () {
    $dir = scratch_dir();
    $envDir = $dir . '/run/env';
    mkdir($envDir, 0700, true);

    $stale = $envDir . '/old.env';
    file_put_contents($stale, 'X=y');
    chmod($stale, 0400);
    touch($stale, time() - 3600);

    $fresh = $envDir . '/fresh.env';
    file_put_contents($fresh, 'X=y');
    chmod($fresh, 0400);

    secretsman_sweep_env_dir($envDir, 300);

    assert_true(!is_file($stale), 'stale env file should be swept');
    assert_true(is_file($fresh), 'fresh env file should survive');
});

// ---------------------------------------------------------------------------
// End-to-end resolve()
// ---------------------------------------------------------------------------

t('resolve: mixed template - env token, plain, escaped literal', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, [
        'ns' => ['a' => 'test-value-not-a-real-secret'],
    ]);
    $xml = [
        'Config' => [
            variable_config('ENV_VAR', '!secret ns/a'),
            variable_config('PLAIN', 'unchanged'),
            variable_config('ESCAPED', '\\!secret ns/a'),
        ],
        'ExtraParams' => '',
    ];
    secretsman_resolve($xml, 'mixedctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);

    // env token entry dropped, plain and escaped-literal entries survive
    // untouched (bar unescaping).
    assert_eq(2, count($xml['Config'])); // PLAIN, ESCAPED
    $targets = array_column($xml['Config'], 'Target');
    assert_true(!in_array('ENV_VAR', $targets, true));
    assert_true(in_array('PLAIN', $targets, true));
    assert_true(in_array('ESCAPED', $targets, true));

    $escaped = array_values(array_filter($xml['Config'], fn($c) => $c['Target'] === 'ESCAPED'))[0];
    assert_eq('!secret ns/a', $escaped['Value']);

    assert_true(strpos($xml['ExtraParams'], '--env-file=') !== false);
});

t('resolve: template with no tokens is left untouched', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'v']]);
    $xml = [
        'Config' => [variable_config('PLAIN', 'unchanged'), path_config('/host', '/container')],
        'ExtraParams' => '--some-flag',
    ];
    $before = $xml;
    secretsman_resolve($xml, 'ctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
    assert_eq($before, $xml);
});

t('resolve: SECRETSMAN_STORE_PATH env var is used only when opts omits store_path', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'from-env-override']]);
    putenv("SECRETSMAN_STORE_PATH={$storePath}");
    putenv("SECRETSMAN_RUNTIME_ROOT={$dir}/run");
    try {
        $xml = ['Config' => [variable_config('V', '!secret ns/k')], 'ExtraParams' => ''];
        secretsman_resolve($xml, 'ctr'); // no $opts at all — the real shipped call shape
        $envFile = glob($dir . '/run/env/*.env')[0] ?? null;
        assert_true($envFile !== null, 'env file should have been written under the env-var runtime root');
        assert_eq("V=from-env-override\n", file_get_contents($envFile));
    } finally {
        putenv('SECRETSMAN_STORE_PATH');
        putenv('SECRETSMAN_RUNTIME_ROOT');
    }
});

t('resolve: an explicit opts[store_path] wins over the env var', function () {
    $dir = scratch_dir();
    $envStorePath = write_store($dir, ['ns' => ['k' => 'from-env-should-not-win']]);
    $optsStorePath = write_store($dir, ['ns' => ['k' => 'from-opts-should-win']], 0600);
    // give the opts store a distinct filename so both can coexist
    rename($optsStorePath, $dir . '/opts-store.json');
    $optsStorePath = $dir . '/opts-store.json';

    putenv("SECRETSMAN_STORE_PATH={$envStorePath}");
    try {
        $xml = ['Config' => [variable_config('V', '!secret ns/k')], 'ExtraParams' => ''];
        secretsman_resolve($xml, 'ctr', ['store_path' => $optsStorePath, 'runtime_root' => $dir . '/run']);
        $envFile = glob($dir . '/run/env/*.env')[0];
        assert_eq("V=from-opts-should-win\n", file_get_contents($envFile));
    } finally {
        putenv('SECRETSMAN_STORE_PATH');
    }
});

// ---------------------------------------------------------------------------
// Store writer (Phase 3 GUI) — secretsman_check_name / _save_store / _store_set
// / _store_delete / _scan_templates
// ---------------------------------------------------------------------------

t('names: check_name accepts a plain ns/key', function () {
    secretsman_check_name('terriblebutler', 'anthropic_api_key');
    assert_true(true); // reaching here without throwing is the assertion
});

t('names: check_name rejects a space in the namespace', function () {
    assert_throws(fn() => secretsman_check_name('my ns', 'key'), SecretsmanError::class, 'namespace');
});

t('names: check_name rejects a slash in the key', function () {
    assert_throws(fn() => secretsman_check_name('ns', 'a/b'), SecretsmanError::class, 'key');
});

t('names: check_name rejects an empty namespace', function () {
    assert_throws(fn() => secretsman_check_name('', 'key'));
});

t('names: check_name rejects leading whitespace that parse_token would otherwise trim away', function () {
    assert_throws(fn() => secretsman_check_name(' ns', 'key'));
});

t('save: save_store writes 0600 and round-trips through load_store', function () {
    $dir = scratch_dir();
    $path = $dir . '/store.json';
    $data = ['ns' => ['k' => 'test-value-not-a-real-secret']];
    secretsman_save_store($path, $data);
    assert_eq('0600', substr(sprintf('%o', fileperms($path)), -4));
    assert_eq($data, secretsman_load_store($path));
});

t('save: save_store creates the parent directory when the store does not exist yet', function () {
    $dir = scratch_dir();
    $path = $dir . '/nested/store.json';
    secretsman_save_store($path, ['ns' => ['k' => 'v']]);
    assert_true(is_file($path));
});

t('save: save_store refuses a value with a newline and leaves the existing store untouched', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'original-value']]);
    $before = file_get_contents($path);
    assert_throws(
        fn() => secretsman_save_store($path, ['ns' => ['k' => "line1\nline2"]]),
        SecretsmanError::class,
        '--env-file'
    );
    assert_eq($before, file_get_contents($path), 'store must be byte-identical after a rejected save');
});

t('save: save_store leaves no .tmp file behind on failure', function () {
    $dir = scratch_dir();
    $path = $dir . '/store.json';
    assert_throws(fn() => secretsman_save_store($path, ['ns' => ['k' => "bad\nvalue"]]));
    assert_eq([], glob($dir . '/*.tmp*'), 'no tmp file should survive a failed save');
});

t('set: store_set adds a new key to a fresh store', function () {
    $dir = scratch_dir();
    $path = $dir . '/store.json';
    secretsman_store_set($path, 'ns', 'k', 'test-value-not-a-real-secret');
    assert_eq('test-value-not-a-real-secret', secretsman_lookup(secretsman_load_store($path), 'ns', 'k'));
});

t('set: store_set refuses to overwrite an existing key unless overwrite is true', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'original']]);
    assert_throws(fn() => secretsman_store_set($path, 'ns', 'k', 'new-value'), SecretsmanError::class, 'already exists');
    secretsman_store_set($path, 'ns', 'k', 'new-value', true);
    assert_eq('new-value', secretsman_lookup(secretsman_load_store($path), 'ns', 'k'));
});

t('set: store_set validates ns/key via check_name before touching the store', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']]);
    assert_throws(fn() => secretsman_store_set($path, 'bad ns', 'k', 'v'));
    assert_eq(['ns' => ['k' => 'v']], secretsman_load_store($path), 'invalid ns/key must not reach the store');
});

t('delete: store_delete removes the key and prunes an emptied namespace', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']]);
    secretsman_store_delete($path, 'ns', 'k');
    assert_eq([], secretsman_load_store($path));
});

t('delete: store_delete leaves sibling keys in the namespace untouched', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k1' => 'v1', 'k2' => 'v2']]);
    secretsman_store_delete($path, 'ns', 'k1');
    assert_eq(['ns' => ['k2' => 'v2']], secretsman_load_store($path));
});

t('delete: store_delete throws for a missing key, matching lookup()', function () {
    $dir = scratch_dir();
    $path = write_store($dir, ['ns' => ['k' => 'v']]);
    assert_throws(fn() => secretsman_store_delete($path, 'ns', 'missing'), SecretsmanError::class, 'no such key');
});

function fixture_scan_template(string $dir, string $name, array $configEntries): void
{
    $xml = "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n  <Name>{$name}</Name>\n";
    foreach ($configEntries as $c) {
        $xml .= sprintf(
            "  <Config Name=\"%s\" Target=\"%s\" Default=\"%s\" Mode=\"\" Type=\"%s\">%s</Config>\n",
            htmlspecialchars($c['name'] ?? 'x'),
            htmlspecialchars($c['target'] ?? 'X'),
            htmlspecialchars($c['default'] ?? ''),
            htmlspecialchars($c['type'] ?? 'Variable'),
            htmlspecialchars($c['value'] ?? '')
        );
    }
    $xml .= "</Container>\n";
    file_put_contents($dir . '/my-' . $name . '.xml', $xml);
}

t('scan: scan_templates maps a token to the container names that reference it', function () {
    $dir = scratch_dir();
    fixture_scan_template($dir, 'ctrA', [['target' => 'V', 'type' => 'Variable', 'value' => '!secret ns/k']]);
    fixture_scan_template($dir, 'ctrB', [['target' => 'V', 'type' => 'Variable', 'value' => '!secret ns/k']]);
    $scan = secretsman_scan_templates($dir);
    assert_eq(['ctrA', 'ctrB'], $scan['usage']['ns/k']);
    assert_eq([], $scan['problems']);
});

t('scan: scan_templates reports a malformed token as a problem, not a usage', function () {
    $dir = scratch_dir();
    fixture_scan_template($dir, 'ctrBad', [['target' => 'V', 'type' => 'Variable', 'value' => '!Secret ns/k']]);
    $scan = secretsman_scan_templates($dir);
    assert_eq([], $scan['usage']);
    assert_eq(1, count($scan['problems']));
    assert_eq('ctrBad', $scan['problems'][0]['container']);
});

t('scan: scan_templates ignores tokens in non-Variable fields', function () {
    $dir = scratch_dir();
    fixture_scan_template($dir, 'ctrPath', [['target' => '/x', 'type' => 'Path', 'default' => '!secret ns/k']]);
    $scan = secretsman_scan_templates($dir);
    assert_eq([], $scan['usage']);
    assert_eq([], $scan['problems']);
});

t('scan: scan_templates returns empty for a directory with no templates', function () {
    $dir = scratch_dir();
    assert_eq(['usage' => [], 'problems' => []], secretsman_scan_templates($dir));
});

// ---------------------------------------------------------------------------
// Never log a resolved secret
// ---------------------------------------------------------------------------

t('never-log: a lookup-failure error never contains the sentinel', function () {
    $dir = scratch_dir();
    $sentinel = 'SUPER-SECRET-SENTINEL-VALUE';
    $storePath = write_store($dir, ['ns' => ['present' => $sentinel]]);
    $xml = ['Config' => [variable_config('V', '!secret ns/missing')], 'ExtraParams' => ''];
    try {
        secretsman_resolve($xml, 'ctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
        throw new \Exception('expected failure');
    } catch (SecretsmanError $e) {
        assert_true(strpos($e->getMessage(), $sentinel) === false, 'sentinel leaked into error message');
    }
});

t('never-log: a scope-violation error never contains the sentinel', function () {
    $dir = scratch_dir();
    $sentinel = 'SUPER-SECRET-SENTINEL-VALUE';
    $storePath = write_store($dir, ['ns' => ['k' => $sentinel]]);
    $xml = ['Config' => [path_config('/host', '!secret ns/k')], 'ExtraParams' => ''];
    try {
        secretsman_resolve($xml, 'ctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
        throw new \Exception('expected failure');
    } catch (SecretsmanError $e) {
        assert_true(strpos($e->getMessage(), $sentinel) === false, 'sentinel leaked into error message');
    }
});

t('never-log: a successful resolve never puts the sentinel in $cmd-facing output ($xml only ever holds an --env-file path)', function () {
    $dir = scratch_dir();
    $sentinel = 'SUPER-SECRET-SENTINEL-VALUE';
    $storePath = write_store($dir, ['ns' => ['k' => $sentinel]]);
    $xml = ['Config' => [variable_config('V', '!secret ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml, 'ctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
    $serialized = json_encode($xml);
    assert_true(strpos($serialized, $sentinel) === false, 'sentinel leaked into resolved $xml');
});

// ---------------------------------------------------------------------------
// Patch layer
// ---------------------------------------------------------------------------

function fixture_helpers_contents(): string
{
    // A minimal but structurally faithful stand-in for xmlToCommand()'s
    // relevant slice: real leading code, then the exact anchor line as it
    // appears in Helpers.php:508, then real trailing code. Good enough to
    // exercise anchor-finding without needing the full 795-line file.
    return "<?php\n" .
        "function xmlToCommand(\$xml, \$create_paths=false) {\n" .
        "  global \$docroot, \$var, \$driver;\n" .
        "  \$xml = xmlToVar(\$xml);\n" .
        "  \$Volumes = [''];\n" .
        SECRETSMAN_PATCH_ANCHOR .
        "    \$confType = strtolower(strval(\$config['Type']));\n" .
        "  }\n" .
        "  return [\$cmd, \$xml['Name'], \$xml['Repository']];\n" .
        "}\n";
}

t('patch: real committed reference/7.3.x/Helpers.php contains exactly one anchor', function () {
    $real = file_get_contents(__DIR__ . '/../reference/7.3.x/Helpers.php');
    $first = strpos($real, SECRETSMAN_PATCH_ANCHOR);
    assert_true($first !== false, 'anchor not found in the real reference file');
    assert_true(strpos($real, SECRETSMAN_PATCH_ANCHOR, $first + 1) === false, 'anchor is not unique in the real reference file');
});

t('patch: verify() reports unpatched-known-good against the real reference hash', function () {
    $real = __DIR__ . '/../reference/7.3.x/Helpers.php';
    $knownHash = md5(file_get_contents($real));
    $result = secretsman_patch_verify($real, [$knownHash]);
    assert_eq('unpatched-known-good', $result['status']);
    assert_eq($knownHash, $result['hash']);
});

t('patch: verify() reports mismatch against an unrecognised hash, and does not throw', function () {
    $real = __DIR__ . '/../reference/7.3.x/Helpers.php';
    $result = secretsman_patch_verify($real, ['0000000000000000000000000000000']);
    assert_eq('mismatch', $result['status']);
});

t('patch: apply() injects the block immediately before the anchor, anchor line itself untouched', function () {
    $patched = secretsman_patch_apply(fixture_helpers_contents(), '/plugin/dir/src/secretsman.php');
    assert_true(secretsman_patch_is_applied($patched));
    assert_true(strpos($patched, SECRETSMAN_PATCH_ANCHOR) !== false, 'original anchor line must survive');
    assert_true(strpos($patched, "secretsman_resolve(\$xml, \$xml['Name']);") !== false);
    assert_true(strpos($patched, "require_once '/plugin/dir/src/secretsman.php';") !== false);
    // the injected call comes BEFORE the loop, not after
    assert_true(strpos($patched, 'secretsman_resolve') < strpos($patched, SECRETSMAN_PATCH_ANCHOR));
});

t('patch: apply() is not idempotent by itself — calling it twice throws', function () {
    $once = secretsman_patch_apply(fixture_helpers_contents(), '/plugin/dir/src/secretsman.php');
    assert_throws(fn() => secretsman_patch_apply($once, '/plugin/dir/src/secretsman.php'), SecretsmanPatchError::class);
});

t('patch: is_applied() is what makes the whole flow idempotent', function () {
    $fresh = fixture_helpers_contents();
    assert_true(!secretsman_patch_is_applied($fresh));
    $once = secretsman_patch_apply($fresh, '/plugin/dir/src/secretsman.php');
    assert_true(secretsman_patch_is_applied($once));
});

t('patch: apply() throws if the anchor is missing entirely', function () {
    assert_throws(fn() => secretsman_patch_apply("<?php\necho 'nothing to see here';\n", '/x'), SecretsmanPatchError::class);
});

t('patch: apply() refuses to guess if the anchor appears more than once', function () {
    $doubled = fixture_helpers_contents() . SECRETSMAN_PATCH_ANCHOR;
    assert_throws(fn() => secretsman_patch_apply($doubled, '/x'), SecretsmanPatchError::class, 'not unique');
});

t('patch: apply_to_file() patches a known-good copy and preserves file mode', function () {
    $dir = scratch_dir();
    $path = $dir . '/Helpers.php';
    file_put_contents($path, fixture_helpers_contents());
    chmod($path, 0644);
    $hash = md5(file_get_contents($path));

    $result = secretsman_patch_apply_to_file($path, '/plugin/dir/src/secretsman.php', [$hash]);
    assert_eq('unpatched-known-good', $result['status']);
    assert_true(secretsman_patch_is_applied(file_get_contents($path)));
    assert_eq('0644', substr(sprintf('%o', fileperms($path)), -4));
});

t('patch: apply_to_file() is a no-op on an already-patched file, leaves it byte-identical', function () {
    $dir = scratch_dir();
    $path = $dir . '/Helpers.php';
    file_put_contents($path, fixture_helpers_contents());
    $hash = md5(file_get_contents($path));
    secretsman_patch_apply_to_file($path, '/plugin/dir/src/secretsman.php', [$hash]);
    $afterFirstPatch = file_get_contents($path);

    $result = secretsman_patch_apply_to_file($path, '/plugin/dir/src/secretsman.php', [$hash]);
    assert_eq('already-patched', $result['status']);
    assert_eq($afterFirstPatch, file_get_contents($path), 'second run must not touch the file at all');
});

t('patch: apply_to_file() on a mismatched file leaves it completely untouched and does not throw', function () {
    $dir = scratch_dir();
    $path = $dir . '/Helpers.php';
    $original = fixture_helpers_contents();
    file_put_contents($path, $original);
    chmod($path, 0644);

    $result = secretsman_patch_apply_to_file($path, '/plugin/dir/src/secretsman.php', ['0000000000000000000000000000000']);
    assert_eq('mismatch', $result['status']);
    assert_eq($original, file_get_contents($path), 'a hash mismatch must never modify the file — fail closed, notify, leave it working');
});

t('patch: block() safely escapes an unusual lib path (var_export, not string concatenation)', function () {
    $weirdPath = "/plugin dir/it's-a-trap\$xml/secretsman.php";
    $block = secretsman_patch_block($weirdPath);
    // must be syntactically valid PHP on its own merits, not just "didn't throw"
    $tmp = tempnam(sys_get_temp_dir(), 'secretsman-lint-');
    file_put_contents($tmp, "<?php\nfunction f(){\n$block}\n");
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    unlink($tmp);
    assert_eq(0, $code, 'injected block with an unusual path must still be valid PHP: ' . implode("\n", $out));
});

t('patch: the real reference/7.3.x/Helpers.php, patched, still lints clean under php -l', function () {
    $real = __DIR__ . '/../reference/7.3.x/Helpers.php';
    $patched = secretsman_patch_apply(file_get_contents($real), '/plugin/dir/src/secretsman.php');
    $tmp = tempnam(sys_get_temp_dir(), 'secretsman-lint-real-');
    file_put_contents($tmp, $patched);
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    unlink($tmp);
    assert_eq(0, $code, 'patched real Helpers.php must lint clean: ' . implode("\n", $out));
});

t('plg: unraid-secretsman.plg is well-formed XML', function () {
    // Caught a real bug live on Gate 1: a bash comment inside an <INLINE>
    // block containing a literal "<name>" (no CDATA is possible here,
    // since CDATA would stop &entity; substitution the .plg depends on)
    // silently corrupted the document structure past that point, and
    // Unraid's installer only reports this as an opaque "XML file doesn't
    // exist or xml parse error" with no line number. Cheap to catch here.
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file(__DIR__ . '/../unraid-secretsman.plg');
    $errors = libxml_get_errors();
    libxml_clear_errors();
    assert_true($xml !== false, 'unraid-secretsman.plg must be valid XML: ' .
        implode('; ', array_map(fn($e) => "line {$e->line}: " . trim($e->message), $errors)));
});

t('plg: FILE elements use the installer\'s actual property names (INLINE, not Inline)', function () {
    // dynamix.plugin.manager/scripts/plugin reads $file->INLINE (all caps)
    // for both content-writing FILE blocks and Run="..." script blocks —
    // a wrong-case element name parses as valid XML but silently does
    // nothing at install time (e.g. cat: file not found). Caught live on
    // Gate 1: <Inline> for the .md5 FILE block was silently ignored.
    $xml = simplexml_load_file(__DIR__ . '/../unraid-secretsman.plg');
    foreach ($xml->FILE as $i => $file) {
        $children = [];
        foreach ($file->children() as $child) {
            $children[] = $child->getName();
        }
        $hasWrongCase = array_filter($children, fn($name) => strcasecmp($name, 'INLINE') === 0 && $name !== 'INLINE');
        assert_true(!$hasWrongCase, "FILE[$i] has a wrong-case INLINE-like element: " . implode(',', $hasWrongCase));
    }
});

t('plg: the packaged .txz extracts to the real absolute install path, not a bare package-name root', function () {
    // Caught live during Gate 2: scripts/build-plugin.sh archived the tree
    // rooted at "unraid-secretsman/...". Slackware's upgradepkg/installpkg
    // extracts packages relative to /, not relative to any "plugins"
    // convention — so this landed everything at /unraid-secretsman instead
    // of /usr/local/emhttp/plugins/unraid-secretsman, and apply_patch.php
    // then failed with "Could not open input file" (exit 1) at boot,
    // silently, since Unraid's installer only captures stdout, not stderr.
    // This runs the real packaging script and inspects the real archive —
    // not a description of what it should do.
    $dir = scratch_dir();
    exec('bash ' . escapeshellarg(__DIR__ . '/../scripts/build-plugin.sh') . ' test-version 2>&1', $out, $code);
    assert_eq(0, $code, 'build-plugin.sh must succeed: ' . implode("\n", $out));

    $txz = __DIR__ . '/../dist/unraid-secretsman-test-version.txz';
    assert_true(is_file($txz), 'expected package not found at ' . $txz);

    exec('tar -tJf ' . escapeshellarg($txz), $entries, $tarCode);
    assert_eq(0, $tarCode, 'tar -tJf must succeed listing the package');
    assert_true(count($entries) > 0, 'package must not be empty');

    foreach ($entries as $entry) {
        assert_true(
            str_starts_with($entry, 'usr/local/emhttp/plugins/unraid-secretsman/') || $entry === 'usr/local/emhttp/plugins/unraid-secretsman/'
                || $entry === 'usr/' || $entry === 'usr/local/' || $entry === 'usr/local/emhttp/' || $entry === 'usr/local/emhttp/plugins/',
            "package entry '$entry' is not rooted at usr/local/emhttp/plugins/unraid-secretsman/ — would extract to the wrong place"
        );
    }

    @unlink($txz);
    @unlink(__DIR__ . '/../dist/unraid-secretsman.md5');
});

// ---------------------------------------------------------------------------
// Plugin scripts (plugin/scripts/) — Phase 2
// ---------------------------------------------------------------------------
// apply_patch_main() hits hardcoded Unraid system paths by design (the live
// Helpers.php) and is validated end-to-end by tests/harness/ against the
// live host, not here. What's tested here is every pure/parameterized piece
// it's built from. (The template-scanning and boot-repopulation pieces that
// used to live here went away with !secretfile — see CLAUDE.md's dated
// correction for why.)

t('patch: secretsman_patch_revert() exactly undoes secretsman_patch_apply()', function () {
    $original = fixture_helpers_contents();
    $patched = secretsman_patch_apply($original, '/plugin/dir/src/secretsman.php');
    $reverted = secretsman_patch_revert($patched);
    assert_eq($original, $reverted);
});

t('patch: secretsman_patch_revert() is a no-op on already-stock content', function () {
    $stock = fixture_helpers_contents();
    assert_eq($stock, secretsman_patch_revert($stock));
});

t('patch: secretsman_patch_revert() round-trips the real reference/7.3.x/Helpers.php', function () {
    $original = file_get_contents(__DIR__ . '/../reference/7.3.x/Helpers.php');
    $patched = secretsman_patch_apply($original, '/plugin/dir/src/secretsman.php');
    assert_eq($original, secretsman_patch_revert($patched));
});

t('apply_patch: detect_unraid_reference_dir() maps 7.3.1 to 7.3.x', function () {
    $dir = scratch_dir();
    $path = $dir . '/unraid-version';
    file_put_contents($path, "version=\"7.3.1\"\n");
    assert_eq('7.3.x', detect_unraid_reference_dir($path));
});

t('apply_patch: detect_unraid_reference_dir() returns null for a missing version file', function () {
    assert_eq(null, detect_unraid_reference_dir('/nonexistent/unraid-version'));
});

t('apply_patch: detect_unraid_reference_dir() returns null for unparseable content', function () {
    $dir = scratch_dir();
    $path = $dir . '/unraid-version';
    file_put_contents($path, "not a version string\n");
    assert_eq(null, detect_unraid_reference_dir($path));
});

t('apply_patch: load_known_good_hashes() parses valid lines, ignores malformed ones', function () {
    $dir = scratch_dir();
    $path = $dir . '/HASHES';
    file_put_contents($path, "# comment-shaped line is not our format, ignored\nHelpers.php  md5:9a45421b387b733ad260e204308baa69\nHelpers.php  md5:notahexhash\n");
    assert_eq(['9a45421b387b733ad260e204308baa69'], load_known_good_hashes($path));
});

t('apply_patch: load_known_good_hashes() returns [] for a missing file', function () {
    assert_eq([], load_known_good_hashes('/nonexistent/HASHES'));
});

// ---------------------------------------------------------------------------

foreach ($failures as $f) {
    fwrite(STDERR, "FAIL: $f\n");
}
printf("%d passed, %d failed\n", $passed, count($failures));
exit(count($failures) === 0 ? 0 : 1);
