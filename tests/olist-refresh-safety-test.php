<?php
declare(strict_types=1);

define('SVRT_LIBRARY_ONLY', true);
require_once __DIR__ . '/../api/olist/refresh-token.php';

function svrt_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function svrt_test_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function svrt_test_remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        svrt_test_remove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

$base = sys_get_temp_dir() . '/svrt-test-' . bin2hex(random_bytes(6));
$release = $base . '/release';
$shared = $base . '/shared';
mkdir($release . '/storage/locks', 0770, true);
mkdir($release . '/storage/monitoring/olist-token', 0770, true);
mkdir($shared, 0770, true);
file_put_contents($release . '/.release-sha', str_repeat('a', 40) . PHP_EOL);
file_put_contents(
    $shared . '/.env',
    "UNRELATED=value\nOLIST_ACCESS_TOKEN=old-access\nOLIST_REFRESH_TOKEN=old-refresh\n"
);
if (!@symlink('../shared/.env', $release . '/.env')) {
    svrt_test_remove($base);
    echo "olist-refresh-safety: skipped (symbolic links unavailable on this Windows runner)\n";
    exit(0);
}
putenv('SVRT_ROOT_OVERRIDE=' . $release);

try {
    $target = svrt_env_target($release . '/.env');
    svrt_test_assert(realpath($target) === realpath($shared . '/.env'), 'symlink must resolve to shared .env');

    svrt_update_env_target($target, [
        'OLIST_ACCESS_TOKEN' => 'new-access',
        'OLIST_REFRESH_TOKEN' => 'new-refresh',
        'TINY_ACCESS_TOKEN' => 'new-access',
    ]);

    svrt_test_assert(is_link($release . '/.env'), 'release .env symlink must remain intact');
    $env = file_get_contents($shared . '/.env');
    svrt_test_assert(is_string($env), 'shared .env must remain readable');
    svrt_test_assert(str_contains($env, 'UNRELATED=value'), 'unrelated env values must be preserved');
    svrt_test_assert(str_contains($env, 'OLIST_REFRESH_TOKEN=new-refresh'), 'refresh token must update shared target');

    $payload = [
        'status' => 'ok',
        'message' => 'test',
        'execution_id' => str_repeat('b', 32),
        'started_at' => gmdate('c'),
        'timestamp' => gmdate('c'),
        'host' => 'test-host',
        'pid' => 123,
        'run_id' => 'test-run',
        'trigger' => 'test',
        'release_sha' => str_repeat('a', 40),
    ];
    $latestPath = svrt_write_monitoring($payload);
    $latest = json_decode((string)file_get_contents($latestPath), true);
    svrt_test_assert(is_array($latest), 'latest evidence must be valid JSON');

    $validated = svrt_validate_evidence($payload, $latest, time() - 5, 'test-run');
    svrt_test_assert($validated['execution_id'] === str_repeat('b', 32), 'current evidence must validate');

    $stale = $latest;
    $stale['execution_id'] = str_repeat('c', 32);
    svrt_test_throws(
        static fn() => svrt_validate_evidence($payload, $stale, time() - 5, 'test-run'),
        'stale or unrelated latest.json must be rejected'
    );

    $broken = $base . '/broken';
    mkdir($broken . '/storage/monitoring', 0770, true);
    file_put_contents($broken . '/storage/monitoring/olist-token', 'not-a-directory');
    putenv('SVRT_ROOT_OVERRIDE=' . $broken);
    svrt_test_throws(
        static fn() => svrt_write_monitoring($payload),
        'monitoring persistence failure must throw'
    );
} finally {
    putenv('SVRT_ROOT_OVERRIDE');
    svrt_test_remove($base);
}

echo "olist-refresh-safety: ok\n";
