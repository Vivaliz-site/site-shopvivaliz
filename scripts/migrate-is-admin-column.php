<?php
/**
 * MIGRATION: Adicionar coluna is_admin na tabela users
 * Execução: php scripts/migrate-is-admin-column.php
 *
 * Status: ✅ CRÍTICO - Sem esta migração, o admin panel não funciona
 *
 * O que faz:
 * 1. Verifica se coluna is_admin já existe
 * 2. Se não existir, cria com DEFAULT 0
 * 3. Define usuário admin como is_admin = 1 (se houver email admin)
 */

declare(strict_types=1);

// Cores para terminal
const COLOR_GREEN = "\033[92m";
const COLOR_RED = "\033[91m";
const COLOR_YELLOW = "\033[93m";
const COLOR_RESET = "\033[0m";

function log_success(string $message): void
{
    echo COLOR_GREEN . "✅ " . $message . COLOR_RESET . "\n";
}

function log_error(string $message): void
{
    echo COLOR_RED . "❌ " . $message . COLOR_RESET . "\n";
}

function log_info(string $message): void
{
    echo COLOR_YELLOW . "ℹ️  " . $message . COLOR_RESET . "\n";
}

// ============================================================================
// STEP 1: Carregar credenciais do .env
// ============================================================================
log_info("Carregando configurações...");

$env_file = __DIR__ . '/../.env';
if (!file_exists($env_file)) {
    log_error("Arquivo .env não encontrado");
    exit(1);
}

$env = parse_ini_file($env_file);
$db_host = $env['DB_HOST'] ?? 'localhost';
$db_user = $env['DB_USER'] ?? 'root';
$db_pass = $env['DB_PASS'] ?? 'root';
$db_name = $env['DB_NAME'] ?? 'shopvivaliz';

log_success("Configurações carregadas");

// ============================================================================
// STEP 2: Conectar ao banco de dados
// ============================================================================
log_info("Conectando ao banco de dados...");

try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($db->connect_error) {
        throw new Exception("Erro de conexão: " . $db->connect_error);
    }

    $db->set_charset("utf8mb4");
    log_success("Conectado ao banco: " . $db_name);
} catch (Exception $e) {
    log_error("Falha ao conectar: " . $e->getMessage());
    exit(1);
}

// ============================================================================
// STEP 2: Verificar se coluna já existe
// ============================================================================
log_info("Verificando se coluna 'is_admin' já existe...");

$result = $db->query("DESCRIBE users is_admin");

if ($result && $result->num_rows > 0) {
    log_success("Coluna 'is_admin' já existe. Migração não necessária.");
    $db->close();
    exit(0);
}

log_info("Coluna 'is_admin' não encontrada. Criando...");

// ============================================================================
// STEP 3: Adicionar coluna is_admin
// ============================================================================
$sql = "ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT 0 AFTER updated_at";

if ($db->query($sql)) {
    log_success("Coluna 'is_admin' criada com sucesso!");
} else {
    log_error("Falha ao criar coluna: " . $db->error);
    $db->close();
    exit(1);
}

// ============================================================================
// STEP 4: Definir admin padrão (opcional)
// ============================================================================
log_info("Procurando usuário admin...");

// Tentar encontrar usuário com email 'admin@shopvivaliz.com.br'
$adminEmail = 'admin@shopvivaliz.com.br';
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $adminEmail);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $adminId = (int)$row['id'];

    $updateStmt = $db->prepare("UPDATE users SET is_admin = 1 WHERE id = ?");
    $updateStmt->bind_param('i', $adminId);

    if ($updateStmt->execute()) {
        log_success("Usuário #$adminId ($adminEmail) definido como admin");
    } else {
        log_error("Falha ao definir admin: " . $db->error);
    }

    $updateStmt->close();
} else {
    log_info("Nenhum usuário 'admin@shopvivaliz.com.br' encontrado");
    log_info("Você pode definir um admin manualmente:");
    log_info("  UPDATE users SET is_admin = 1 WHERE id = [USER_ID];");
}

$stmt->close();

// ============================================================================
// STEP 5: Validação final
// ============================================================================
log_info("Validando coluna criada...");

$validateResult = $db->query("DESCRIBE users is_admin");
if ($validateResult && $validateResult->num_rows > 0) {
    log_success("Migração completada com sucesso!");
    $db->close();
    exit(0);
} else {
    log_error("Validação falhou - coluna não foi criada");
    $db->close();
    exit(1);
}
