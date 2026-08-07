<?php

declare(strict_types=1);

$sharedEnv = getenv('SHOPVIVALIZ_SHARED_ENV')
    ?: '/home/ubuntu/shopvivaliz-deploy/shared/.env';
$outputPath = getenv('SHOPVIVALIZ_RUNTIME_SECRETS')
    ?: '/home/ubuntu/shopvivaliz-deploy/shared/runtime-secrets.php';
$sharedGroup = getenv('SHOPVIVALIZ_SHARED_GROUP') ?: 'www-data';

if (!is_file($sharedEnv) || !is_readable($sharedEnv)) {
    throw new RuntimeException('shared_env_unavailable');
}

// Pagar.me foi aposentado do projeto. Remova qualquer chave residual do
// runtime compartilhado de forma atomica, sem carregar ou registrar valores.
$envLines = file($sharedEnv, FILE_IGNORE_NEW_LINES);
if ($envLines === false) {
    throw new RuntimeException('shared_env_read_failed');
}
$retainedLines = [];
$retiredRuntimeKeysRemoved = 0;
foreach ($envLines as $line) {
    $candidate = trim($line);
    if (str_starts_with($candidate, 'export ')) {
        $candidate = trim(substr($candidate, 7));
    }
    if ($candidate !== '' && !str_starts_with($candidate, '#') && str_contains($candidate, '=')) {
        [$candidateKey] = explode('=', $candidate, 2);
        if (str_starts_with(trim($candidateKey), 'PAGARME_')) {
            $retiredRuntimeKeysRemoved++;
            continue;
        }
    }
    $retainedLines[] = $line;
}
if ($retiredRuntimeKeysRemoved > 0) {
    $envDir = dirname($sharedEnv);
    $envTemp = tempnam($envDir, '.shared-env-retire.');
    if ($envTemp === false) {
        throw new RuntimeException('shared_env_temporary_file_failed');
    }
    try {
        $envPayload = implode(PHP_EOL, $retainedLines) . PHP_EOL;
        if (file_put_contents($envTemp, $envPayload, LOCK_EX) === false) {
            throw new RuntimeException('shared_env_retirement_write_failed');
        }
        if (!chmod($envTemp, 0640)) {
            throw new RuntimeException('shared_env_retirement_mode_failed');
        }
        if (!rename($envTemp, $sharedEnv)) {
            throw new RuntimeException('shared_env_retirement_replace_failed');
        }
    } finally {
        if (is_file($envTemp)) {
            @unlink($envTemp);
        }
    }
}

if (PHP_OS_FAMILY !== 'Windows' && !chgrp($sharedEnv, $sharedGroup)) {
    throw new RuntimeException('shared_env_group_failed');
}
if (!chmod($sharedEnv, 0640)) {
    throw new RuntimeException('shared_env_web_mode_failed');
}

// Runtime secrets are a PHP-readable fallback loaded by config/constants.php
// before .env. Marketplace credentials are included because admin publication
// executes under the web user and must survive an accidental .env permission
// regression. Values are never logged and the generated file remains 0640.
$allowedKeys = [
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASS',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD',
    'QUOTE_SIGNING_KEY',
    'APP_KEY',
    'SHOPVIVALIZ_APP_KEY',
    'SHOPVIVALIZ_AGENT_KEY',
    'OLIST_WEBHOOK_SECRET',

    'ML_CLIENT_ID',
    'ML_CLIENT_SECRET',
    'ML_ACCESS_TOKEN',
    'ML_REFRESH_TOKEN',
    'ML_SELLER_ID',
    'MERCADO_LIVRE_CLIENT_ID',
    'MERCADO_LIVRE_CLIENT_SECRET',
    'MERCADO_LIVRE_ACCESS_TOKEN',
    'MERCADO_LIVRE_REFRESH_TOKEN',
    'MERCADO_LIVRE_SELLER_ID',

    'SHOPEE_PARTNER_ID',
    'SHOPEE_PARTNER_KEY',
    'SHOPEE_SHOP_ID',
    'SHOPEE_ACCESS_TOKEN',
    'SHOPEE_REFRESH_TOKEN',
    'SHOPEE_TOKEN_FILE',

    'TIKTOK_APP_KEY',
    'TIKTOK_CLIENT_ID',
    'TIKTOK_APP_SECRET',
    'TIKTOK_CLIENT_SECRET',
    'TIKTOK_ACCESS_TOKEN',
    'TIKTOK_REFRESH_TOKEN',
    'TIKTOK_SHOP_CIPHER',
    'TIKTOK_SHOP_ID',
    'TIKTOK_TOKEN_FILE',

    'AMAZON_LWA_CLIENT_ID',
    'AMAZON_LWA_CLIENT_SECRET',
    'AMAZON_LWA_REFRESH_TOKEN',
    'AMAZON_SELLER_ID',
    'AMAZON_ACCOUNT_ID',
    'AMAZON_MARKETPLACE_ID',
    'AMAZON_SP_API_ENDPOINT',
    'AMAZON_SP_API_REGION',

    'OLIST_ACCESS_TOKEN',
    'OLIST_REFRESH_TOKEN',
    'OLIST_CLIENT_ID',
    'OLIST_CLIENT_SECRET',
    'OLIST_API_KEY',
    'TINY_ACCESS_TOKEN',
    'TINY_REFRESH_TOKEN',
    'TINY_CLIENT_ID',
    'TINY_CLIENT_SECRET',
    'TOKEN_API_OLIST',
];

$values = [];
foreach (file($sharedEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    if (str_starts_with($line, 'export ')) {
        $line = trim(substr($line, 7));
    }
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    if (!in_array($key, $allowedKeys, true)) {
        continue;
    }
    $value = trim($value);
    if (strlen($value) >= 2) {
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
    }
    if ($value !== '') {
        $values[$key] = $value;
    }
}

$databaseName = trim((string)($values['DB_NAME'] ?? $values['DB_DATABASE'] ?? ''));
$databaseUser = trim((string)($values['DB_USER'] ?? $values['DB_USERNAME'] ?? ''));
$signingKey = trim((string)(
    $values['QUOTE_SIGNING_KEY']
    ?? $values['APP_KEY']
    ?? $values['SHOPVIVALIZ_APP_KEY']
    ?? $values['SHOPVIVALIZ_AGENT_KEY']
    ?? ''
));

if ($databaseName === '' || $databaseUser === '' || strtolower($databaseUser) === 'root') {
    throw new RuntimeException('safe_database_tuple_missing');
}
if (strlen($signingKey) < 32) {
    throw new RuntimeException('quote_signing_key_missing');
}

ksort($values);
$payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
    . var_export($values, true)
    . ";\n";

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true) && !is_dir($outputDir)) {
    throw new RuntimeException('runtime_secrets_directory_failed');
}

$tempPath = tempnam($outputDir, '.runtime-secrets.');
if ($tempPath === false) {
    throw new RuntimeException('temporary_file_failed');
}

try {
    if (file_put_contents($tempPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException('runtime_secrets_write_failed');
    }
    chmod($tempPath, 0640);
    if (!rename($tempPath, $outputPath)) {
        throw new RuntimeException('runtime_secrets_replace_failed');
    }
    chmod($outputPath, 0640);
} finally {
    if (is_file($tempPath)) {
        @unlink($tempPath);
    }
}

$written = require $outputPath;
$writtenUser = trim((string)($written['DB_USER'] ?? $written['DB_USERNAME'] ?? ''));
if (!is_array($written) || $writtenUser === '' || strtolower($writtenUser) === 'root') {
    throw new RuntimeException('runtime_secrets_validation_failed');
}

echo "runtime_secrets_materialized=true\n";
echo 'runtime_key_count=' . count($values) . "\n";
echo 'retired_runtime_keys_removed=' . $retiredRuntimeKeysRemoved . "\n";
echo "database_tuple_present=true\n";
echo "quote_signing_present=true\n";
echo "shared_env_mode=640\n";
