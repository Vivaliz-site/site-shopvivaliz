<?php
declare(strict_types=1);

function svfc_release_marker(string $root): string
{
    foreach ([$root . '/.release-commit', $root . '/REVISION', $root . '/.git/refs/heads/main'] as $path) {
        if (is_file($path)) {
            $value = trim((string)@file_get_contents($path));
            if ($value !== '') return substr($value, 0, 40);
        }
    }
    return 'unknown-release';
}

function svfc_dependency_fingerprint(string $root, array $relativePaths): string
{
    $parts = [svfc_release_marker($root)];
    foreach ($relativePaths as $relativePath) {
        $path = $root . '/' . ltrim((string)$relativePath, '/');
        if (!is_file($path)) {
            $parts[] = $relativePath . ':missing';
            continue;
        }
        $parts[] = $relativePath . ':' . (string)@filemtime($path) . ':' . (string)@filesize($path);
    }
    return hash('sha256', implode('|', $parts));
}

function svfc_cache_dir(string $root): string
{
    $dir = $root . '/storage/cache/google-feeds';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function svfc_start(string $feedName, int $ttlSeconds, array $dependencies): void
{
    if (PHP_SAPI === 'cli') return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    $root = dirname(__DIR__);
    $fingerprint = svfc_dependency_fingerprint($root, $dependencies);
    $safeName = preg_replace('/[^a-z0-9_-]+/i', '-', $feedName) ?: 'feed';
    $file = svfc_cache_dir($root) . '/' . $safeName . '-' . $fingerprint . '.xml';

    if (is_file($file) && (time() - (int)@filemtime($file)) <= $ttlSeconds) {
        header('X-SV-Feed-Cache: HIT');
        readfile($file);
        exit;
    }

    header('X-SV-Feed-Cache: MISS');
    ob_start(static function (string $buffer) use ($file): string {
        $trimmed = ltrim($buffer);
        if ($buffer !== '' && str_starts_with($trimmed, '<?xml') && str_contains($buffer, '<item>')) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $buffer, LOCK_EX) !== false) {
                @rename($tmp, $file);
            }
        }
        return $buffer;
    });
}

function svfc_default_catalog_dependencies(): array
{
    return [
        'storage/products-cache-ativos.json',
        'api/catalog/fallback-products.json',
        'storage/tiny/categories-flat.json',
        'config/official-site.php',
    ];
}
