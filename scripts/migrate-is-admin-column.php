<?php
/**
 * MIGRATION: garantir coluna is_admin e autorizacao de uma identidade
 * administrativa ShopVivaliz persistida.
 * Execucao: php scripts/migrate-is-admin-column.php
 *
 * Idempotente e restritiva:
 * 1. Cria users.is_admin apenas se a coluna nao existir.
 * 2. Prefere admin@shopvivaliz.com.br quando essa conta existe.
 * 3. Na ausencia dela, aceita somente uma unica conta @shopvivaliz.com.br.
 * 4. Nunca cria usuario, altera senha ou escolhe entre multiplas contas.
 */

declare(strict_types=1);

const COLOR_GREEN = "\033[92m";
const COLOR_RED = "\033[91m";
const COLOR_YELLOW = "\033[93m";
const COLOR_RESET = "\033[0m";
const CANONICAL_ADMIN_EMAIL = 'admin@shopvivaliz.com.br';

function log_success(string $message): void
{
    echo COLOR_GREEN . "OK " . $message . COLOR_RESET . "\n";
}

function log_error(string $message): void
{
    echo COLOR_RED . "ERRO " . $message . COLOR_RESET . "\n";
}

function log_info(string $message): void
{
    echo COLOR_YELLOW . "INFO " . $message . COLOR_RESET . "\n";
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

log_info('Carregando configuracoes...');
$envFile = __DIR__ . '/../.env';
if (!is_file($envFile) || !is_readable($envFile)) {
    log_error('Arquivo .env nao encontrado ou ilegivel');
    exit(1);
}

$env = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $trimmed, 2);
    $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
}

$dbHost = $env['DB_HOST'] ?? 'localhost';
$dbUser = $env['DB_USER'] ?? ($env['DB_USERNAME'] ?? '');
$dbPass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$dbName = $env['DB_NAME'] ?? 'shopvivaliz';
if ($dbUser === '') {
    log_error('DB_USER/DB_USERNAME ausente');
    exit(1);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $db->set_charset('utf8mb4');

    $columnResult = $db->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
    if ($columnResult->num_rows === 0) {
        $db->query('ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0');
        log_success("Coluna 'is_admin' criada");
    } else {
        log_success("Coluna 'is_admin' ja existe");
    }

    $adminId = 0;
    $adminFlag = 0;
    $stmt = $db->prepare('SELECT id, is_admin FROM users WHERE LOWER(email) = ? LIMIT 1');
    $canonicalEmail = CANONICAL_ADMIN_EMAIL;
    $stmt->bind_param('s', $canonicalEmail);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($admin && !empty($admin['id'])) {
        $adminId = (int)$admin['id'];
        $adminFlag = (int)($admin['is_admin'] ?? 0);
        log_info('Identidade administrativa resolvida pela conta canonica');
    } else {
        $domainPattern = '%@shopvivaliz.com.br';
        $domainStmt = $db->prepare(
            'SELECT id, is_admin FROM users WHERE LOWER(email) LIKE ? ORDER BY id ASC LIMIT 2'
        );
        $domainStmt->bind_param('s', $domainPattern);
        $domainStmt->execute();
        $result = $domainStmt->get_result();
        $domainAccounts = [];
        while ($row = $result->fetch_assoc()) {
            $domainAccounts[] = $row;
        }
        $domainStmt->close();

        if (count($domainAccounts) === 0) {
            throw new RuntimeException('Nenhuma conta ShopVivaliz persistida foi encontrada');
        }
        if (count($domainAccounts) !== 1 || empty($domainAccounts[0]['id'])) {
            throw new RuntimeException('Existem multiplas contas ShopVivaliz; recusando escolher admin automaticamente');
        }

        $adminId = (int)$domainAccounts[0]['id'];
        $adminFlag = (int)($domainAccounts[0]['is_admin'] ?? 0);
        log_info('Identidade administrativa resolvida pela unica conta do dominio');
    }

    if ($adminFlag !== 1) {
        $update = $db->prepare('UPDATE users SET is_admin = 1 WHERE id = ?');
        $update->bind_param('i', $adminId);
        $update->execute();
        $update->close();
        log_success('Permissao administrativa restaurada');
    }

    $verify = $db->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
    $verify->bind_param('i', $adminId);
    $verify->execute();
    $verified = $verify->get_result()->fetch_assoc();
    $verify->close();
    if (!$verified || (int)($verified['is_admin'] ?? 0) !== 1) {
        throw new RuntimeException('Validacao final da permissao administrativa falhou');
    }

    log_success('Migracao de autorizacao administrativa concluida');
    $db->close();
    exit(0);
} catch (Throwable $error) {
    log_error($error->getMessage());
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    exit(1);
}
