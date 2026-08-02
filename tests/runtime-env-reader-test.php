<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/runtime-env-reader.php';

function svre_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$base = sys_get_temp_dir() . '/svre-test-' . bin2hex(random_bytes(6));
$root = $base . '/current';
$shared = $base . '/shared';
$config = $root . '/config';

mkdir($root, 0700, true);
mkdir($shared, 0700, true);
mkdir($config, 0700, true);

try {
    file_put_contents($root . '/.env', implode("\n", [
        'SVRE_TEST_LOCAL="local value"',
        'export SVRE_TEST_EXPORT=exported',
        'SVRE_TEST_EMPTY=',
        '',
    ]));
    file_put_contents($shared . '/.env', implode("\n", [
        'SVRE_TEST_SHARED=shared-value',
        'SVRE_TEST_LOCAL=shared-must-not-win',
        '',
    ]));
    file_put_contents($config . '/runtime-secrets.php', "<?php return ['SVRE_TEST_SECRET' => 'secret-value'];\n");

    svre_test_assert(svre_value('SVRE_TEST_LOCAL', $root) === 'local value', 'release .env should be read');
    svre_test_assert(svre_value('SVRE_TEST_EXPORT', $root) === 'exported', 'export prefix should be supported');
    svre_test_assert(svre_value('SVRE_TEST_SHARED', $root) === 'shared-value', 'shared .env should be read');
    svre_test_assert(svre_value('SVRE_TEST_SECRET', $root) === 'secret-value', 'runtime secrets should be read');
    svre_test_assert(svre_value(['SVRE_TEST_EMPTY', 'SVRE_TEST_SHARED'], $root) === 'shared-value', 'empty values should fall through');
    svre_test_assert(svre_value('SVRE_TEST_MISSING', $root) === '', 'missing values should remain empty');

    $_SERVER['SVRE_TEST_SCOPE'] = 'server-value';
    svre_test_assert(svre_value('SVRE_TEST_SCOPE', $root) === 'server-value', 'server scope should be supported');
    unset($_SERVER['SVRE_TEST_SCOPE']);

    echo "OK: runtime env reader\n";
} finally {
    @unlink($config . '/runtime-secrets.php');
    @unlink($root . '/.env');
    @unlink($shared . '/.env');
    @rmdir($config);
    @rmdir($root);
    @rmdir($shared);
    @rmdir($base);
}
