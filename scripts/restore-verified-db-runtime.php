<?php

declare(strict_types=1);

$shared = '/home/ubuntu/shopvivaliz-deploy/shared/.env';
$keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

function svrestore_parse_env(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $values[$key] = $value;
    }

    return $values;
}

function svrestore_verified_db(array $values): bool
{
    $name = trim((string)($values['DB_NAME'] ?? ''));
    $user = trim((string)($values['DB_USER'] ?? ''));
    if ($name === '' || $user === '' || strtolower($user) === 'root') {
        return false;
    }

    $host = trim((string)($values['DB_HOST'] ?? 'localhost')) ?: 'localhost';
    $portRaw = trim((string)($values['DB_PORT'] ?? '3306'));
    $port = ctype_digit($portRaw) ? (int)$portRaw : 3306;

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            (string)($values['DB_PASS'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $tables = (int)$pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('orders','blog_articles')"
        )->fetchColumn();
        return $tables === 2;
    } catch (Throwable) {
        return false;
    }
}

if (!is_file($shared) || !is_readable($shared) || !is_writable($shared)) {
    throw new RuntimeException('shared_environment_unavailable');
}

$backups = glob($shared . '.backup.*') ?: [];
usort($backups, static fn(string $a, string $b): int => ((int)(filemtime($b) ?: 0)) <=> ((int)(filemtime($a) ?: 0)));

$verified = [];
foreach ($backups as $backup) {
    $candidate = svrestore_parse_env($backup);
    if (svrestore_verified_db($candidate)) {
        $verified = $candidate;
        break;
    }
}
if ($verified === []) {
    throw new RuntimeException('no_verified_db_backup');
}

$current = svrestore_parse_env($shared);
foreach ($keys as $key) {
    $value = (string)($verified[$key] ?? '');
    if (in_array($key, ['DB_NAME', 'DB_USER'], true) && trim($value) === '') {
        throw new RuntimeException('verified_key_missing');
    }
    $current[$key] = $value;
}
if (!svrestore_verified_db($current)) {
    throw new RuntimeException('restored_candidate_invalid');
}

$mode = (int)(fileperms($shared) & 0777);
$safetyBackup = $shared . '.backup.restore.' . time();
if (!copy($shared, $safetyBackup)) {
    throw new RuntimeException('safety_backup_failed');
}
chmod($safetyBackup, $mode);

$lines = file($shared, FILE_IGNORE_NEW_LINES) ?: [];
$output = [];
$written = [];
foreach ($lines as $line) {
    $key = str_contains($line, '=') ? trim(explode('=', $line, 2)[0]) : '';
    if (in_array($key, $keys, true)) {
        $output[] = $key . '=' . $current[$key];
        $written[$key] = true;
    } else {
        $output[] = $line;
    }
}
foreach ($keys as $key) {
    if (!isset($written[$key])) {
        $output[] = $key . '=' . $current[$key];
    }
}

$temp = tempnam(dirname($shared), '.env.restore.');
if ($temp === false) {
    throw new RuntimeException('temporary_file_failed');
}
try {
    if (file_put_contents($temp, rtrim(implode("\n", $output), "\n") . "\n", LOCK_EX) === false) {
        throw new RuntimeException('temporary_write_failed');
    }
    chmod($temp, $mode);
    if (!rename($temp, $shared)) {
        throw new RuntimeException('atomic_replace_failed');
    }
} finally {
    if (is_file($temp)) {
        @unlink($temp);
    }
}

if (!svrestore_verified_db(svrestore_parse_env($shared))) {
    copy($safetyBackup, $shared);
    chmod($shared, $mode);
    throw new RuntimeException('post_write_failed_rollback_applied');
}

echo "working_backup_selected=true\n";
echo "database_keys_restored=5\n";
echo "current_database_connected=true\n";
echo "safety_backup_created=true\n";
