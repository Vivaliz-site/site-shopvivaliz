<?php

declare(strict_types=1);

function shopvivaliz_internal_origin(): string
{
    $fallback = 'http://127.0.0.1:8080';
    $configured = getenv('SHOPVIVALIZ_INTERNAL_ORIGIN');
    if (!is_string($configured) || trim($configured) === '') {
        return $fallback;
    }

    $candidate = rtrim(trim($configured), '/');
    $parts = parse_url($candidate);
    if (!is_array($parts)) {
        return $fallback;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = (int)($parts['port'] ?? 0);
    $path = (string)($parts['path'] ?? '');
    $hasCredentials = isset($parts['user']) || isset($parts['pass']);
    $hasExtra = isset($parts['query']) || isset($parts['fragment']);

    if ($scheme !== 'http'
        || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
        || $port < 1 || $port > 65535
        || ($path !== '' && $path !== '/')
        || $hasCredentials || $hasExtra) {
        return $fallback;
    }

    return $candidate;
}

function shopvivaliz_internal_url(string $target): string
{
    if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//')) {
        throw new InvalidArgumentException('Internal target must be an absolute local path.');
    }

    return shopvivaliz_internal_origin() . $target;
}
