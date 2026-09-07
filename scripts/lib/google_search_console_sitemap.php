<?php
declare(strict_types=1);

function shopvivaliz_gsc_safe_sitemap_file(string $root, string $input): string
{
    $input = trim($input);
    if ($input === '') {
        throw new RuntimeException('Sitemap file path is required.');
    }
    if (preg_match('~^[A-Za-z]:[\\/]~', $input) || str_starts_with($input, '/') || str_contains($input, "\0")) {
        throw new RuntimeException('Sitemap file path must be relative to reports/.');
    }
    $normalized = str_replace('\\', '/', $input);
    $parts = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
    if ($parts === [] || $parts[0] !== 'reports' || in_array('..', $parts, true)) {
        throw new RuntimeException('Sitemap file path must be inside reports/.');
    }
    if (!str_ends_with(strtolower($normalized), '.xml')) {
        throw new RuntimeException('Sitemap file must use a .xml extension.');
    }
    $path = rtrim($root, '/\\') . '/' . implode('/', $parts);
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Sitemap file is not readable.');
    }
    return $path;
}

function shopvivaliz_gsc_read_sitemap_file(string $root, string $input): string
{
    $path = shopvivaliz_gsc_safe_sitemap_file($root, $input);
    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        throw new RuntimeException('Sitemap file is empty or unreadable.');
    }
    return $contents;
}
