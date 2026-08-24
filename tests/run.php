<?php
declare(strict_types=1);

// Plain-PHP assert runner. No framework, no Composer: `php tests/run.php`,
// identical locally and in CI.

require __DIR__ . '/../src/secretsman.php';

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

t('parse: valid !secretfile', function () {
    $p = secretsman_parse_token('!secretfile media/plex_token');
    assert_eq('token', $p['kind']);
    assert_eq('file', $p['mode']);
    assert_eq('media', $p['ns']);
    assert_eq('plex_token', $p['key']);
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

t('parse: escaped secretfile literal', function () {
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

t('materialise: secretfile writes a 0400 file and rewrites the Variable to _FILE', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $xml = ['Config' => [variable_config('MY_TOKEN', '!secretfile ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml, 'testctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);

    assert_eq(2, count($xml['Config']));
    $varEntry = $xml['Config'][0];
    assert_eq('MY_TOKEN_FILE', $varEntry['Target']);
    assert_eq('/run/secrets/k', $varEntry['Value']);

    $pathEntry = $xml['Config'][1];
    assert_eq('Path', $pathEntry['Type']);
    assert_eq('/run/secrets/k', $pathEntry['Target']);
    assert_eq('ro', $pathEntry['Mode']);
    assert_true(is_file($pathEntry['Value']));
    assert_eq('test-value-not-a-real-secret', file_get_contents($pathEntry['Value']));
    assert_eq('0400', substr(sprintf('%o', fileperms($pathEntry['Value'])), -4));
});

t('materialise: re-running resolve() is idempotent (reboot-repopulate case)', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, ['ns' => ['k' => 'test-value-not-a-real-secret']]);
    $opts = ['store_path' => $storePath, 'runtime_root' => $dir . '/run'];

    $xml1 = ['Config' => [variable_config('MY_TOKEN', '!secretfile ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml1, 'testctr', $opts);
    $path1 = $xml1['Config'][1]['Value'];

    $xml2 = ['Config' => [variable_config('MY_TOKEN', '!secretfile ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml2, 'testctr', $opts);
    $path2 = $xml2['Config'][1]['Value'];

    assert_eq($path1, $path2);
    assert_eq('test-value-not-a-real-secret', file_get_contents($path2));
});

t('materialise: sweeper deletes stale env files, never touches files/', function () {
    $dir = scratch_dir();
    $envDir = $dir . '/run/env';
    $fileDir = $dir . '/run/files/keep-me';
    mkdir($envDir, 0700, true);
    mkdir($fileDir, 0700, true);

    $stale = $envDir . '/old.env';
    file_put_contents($stale, 'X=y');
    chmod($stale, 0400);
    touch($stale, time() - 3600);

    $fresh = $envDir . '/fresh.env';
    file_put_contents($fresh, 'X=y');
    chmod($fresh, 0400);

    $secretFile = $fileDir . '/k';
    file_put_contents($secretFile, 'test-value-not-a-real-secret');
    chmod($secretFile, 0400);
    touch($secretFile, time() - 3600);

    secretsman_sweep_env_dir($envDir, 300);

    assert_true(!is_file($stale), 'stale env file should be swept');
    assert_true(is_file($fresh), 'fresh env file should survive');
    assert_true(is_file($secretFile), 'files/ is never swept');
});

// ---------------------------------------------------------------------------
// End-to-end resolve()
// ---------------------------------------------------------------------------

t('resolve: mixed template - env token, file token, plain, escaped literal', function () {
    $dir = scratch_dir();
    $storePath = write_store($dir, [
        'ns' => ['a' => 'test-value-not-a-real-secret', 'b' => 'test-file-secret-value'],
    ]);
    $xml = [
        'Config' => [
            variable_config('ENV_VAR', '!secret ns/a'),
            variable_config('FILE_VAR', '!secretfile ns/b'),
            variable_config('PLAIN', 'unchanged'),
            variable_config('ESCAPED', '\\!secret ns/a'),
        ],
        'ExtraParams' => '',
    ];
    secretsman_resolve($xml, 'mixedctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);

    // env token entry dropped, file token entry rewritten + volume appended,
    // plain and escaped-literal entries survive untouched (bar unescaping).
    assert_eq(4, count($xml['Config'])); // PLAIN, ESCAPED, FILE_VAR renamed, +1 Path
    $targets = array_column($xml['Config'], 'Target');
    assert_true(!in_array('ENV_VAR', $targets, true));
    assert_true(in_array('FILE_VAR_FILE', $targets, true));
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

t('never-log: a successful resolve never puts the sentinel in $cmd-facing output ($xml itself only holds a path)', function () {
    $dir = scratch_dir();
    $sentinel = 'SUPER-SECRET-SENTINEL-VALUE';
    $storePath = write_store($dir, ['ns' => ['k' => $sentinel]]);
    $xml = ['Config' => [variable_config('V', '!secretfile ns/k')], 'ExtraParams' => ''];
    secretsman_resolve($xml, 'ctr', ['store_path' => $storePath, 'runtime_root' => $dir . '/run']);
    $serialized = json_encode($xml);
    assert_true(strpos($serialized, $sentinel) === false, 'sentinel leaked into resolved $xml');
});

// ---------------------------------------------------------------------------

foreach ($failures as $f) {
    fwrite(STDERR, "FAIL: $f\n");
}
printf("%d passed, %d failed\n", $passed, count($failures));
exit(count($failures) === 0 ? 0 : 1);
