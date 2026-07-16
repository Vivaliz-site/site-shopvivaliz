<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap-env.php';

function svoe_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
    }
    return '';
}

function svoe_access_token(): string
{
