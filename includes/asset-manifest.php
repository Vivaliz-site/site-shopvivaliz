<?php
declare(strict_types=1);

function sv_asset_manifest(): array
{
    static $manifest = null;
    if (is_array($manifest)) {
        return $manifest;
    }

    $path = dirname(__DIR__) . '/public/dist/asset-manifest.json';
    if (!is_file($path) || !is_readable($path)) {
        return $manifest = [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    $assets = is_array($decoded['assets'] ?? null) ? $decoded['assets'] : [];
    return $manifest = $assets;
}

function sv_asset_url(string $path): string
{
    $normalized = '/' . ltrim($path, '/');
    $manifest = sv_asset_manifest();
    $entry = $manifest[$normalized] ?? null;
    if (is_array($entry) && is_string($entry['file'] ?? null) && $entry['file'] !== '') {
        $distAbsolute = dirname(__DIR__) . '/' . ltrim($entry['file'], '/');
        if (is_file($distAbsolute)) {
            return $entry['file'];
        }
    }

    $absolute = dirname(__DIR__) . $normalized;
    if (is_file($absolute)) {
        return $normalized . '?v=' . (string)filemtime($absolute);
    }

    return $normalized;
}
