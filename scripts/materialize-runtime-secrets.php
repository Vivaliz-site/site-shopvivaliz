<?php

declare(strict_types=1);

$sharedEnv = '/home/ubuntu/shopvivaliz-deploy/shared/.env';
$outputPath = '/home/ubuntu/shopvivaliz-deploy/shared/runtime-secrets.php';

if (!is_file($sharedEnv) || !is_readable($sharedEnv)) {
    throw new RuntimeException('shared_env_unavailable');
}

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

$tempPath = tempnam(dirname($outputPath), '.runtime-secrets.');
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
if (!is_array($written) || trim((string)($written['DB_USER'] ?? $written['DB_USERNAME'] ?? '')) === '') {
    throw new RuntimeException('runtime_secrets_validation_failed');
}

echo "runtime_secrets_materialized=true\n";
echo 'runtime_key_count=' . count($values) . "\n";
echo "database_tuple_present=true\n";
echo "quote_signing_present=true\n";
