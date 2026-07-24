<?php
/**
 * MAINTENANCE: Corrigir produtos com preço = 0
 * Execução: php scripts/fix-zero-price-products.php
 *
 * Problema: Produtos com price = 0 são invisíveis na busca e checkout
 * Solução: Consoante com Olist, atribuir preço mínimo (R$ 0.01) ou marcar como inativos
 *
 * Opções:
 * 1. --set-min-price: Define preço mínimo (R$ 0.01)
 * 2. --mark-inactive: Marca como inativos (active = 0)
 * 3. --list: Lista produtos com price = 0 sem modificar
 */

declare(strict_types=1);

const COLOR_GREEN = "\033[92m";
const COLOR_RED = "\033[91m";
const COLOR_YELLOW = "\033[93m";
const COLOR_BLUE = "\033[94m";
const COLOR_RESET = "\033[0m";

function log_success(string $msg): void { echo COLOR_GREEN . "✅ " . $msg . COLOR_RESET . "\n"; }
function log_error(string $msg): void { echo COLOR_RED . "❌ " . $msg . COLOR_RESET . "\n"; }
function log_info(string $msg): void { echo COLOR_YELLOW . "ℹ️  " . $msg . COLOR_RESET . "\n"; }
function log_debug(string $msg): void { echo COLOR_BLUE . "🔍 " . $msg . COLOR_RESET . "\n"; }

// Carregar .env
log_info("Carregando configurações...");
$env_file = __DIR__ . '/../.env';
if (!file_exists($env_file)) {
    log_error("Arquivo .env não encontrado");
    exit(1);
}

$env = [];
$lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos($line, '=') === false || strpos($line, '#') === 0) continue;
    list($key, $value) = explode('=', $line, 2);
    $env[trim($key)] = trim($value);
}

// Conectar ao banco
$db = new mysqli(
    $env['DB_HOST'] ?? 'localhost',
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? 'root',
    $env['DB_NAME'] ?? 'shopvivaliz'
);

if ($db->connect_error) {
    log_error("Falha ao conectar: " . $db->connect_error);
    exit(1);
}

$db->set_charset("utf8mb4");
log_success("Conectado ao banco de dados");

// ============================================================================
// Obter opção de ação
// ============================================================================
$action = $argv[1] ?? '--list';
log_info("Ação: $action");

// ============================================================================
// STEP 1: Contar produtos com price = 0
// ============================================================================
log_info("Procurando produtos com price = 0...");

$result = $db->query("
    SELECT id, sku, name, price, active, stock
    FROM products
    WHERE price <= 0 OR price IS NULL
    ORDER BY name ASC
");

if (!$result) {
    log_error("Erro na query: " . $db->error);
    exit(1);
}

$count = $result->num_rows;
log_info("Encontrados: $count produtos com price = 0");

// ============================================================================
// STEP 2: Listar produtos
// ============================================================================
if ($count === 0) {
    log_success("Nenhum produto com preço = 0. Tudo OK!");
    exit(0);
}

echo "\n" . COLOR_BLUE . "📋 PRODUTOS COM PREÇO = 0:" . COLOR_RESET . "\n";
echo "─────────────────────────────────────────────────────────────────\n";

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
    printf("  ID: %-5s | SKU: %-20s | Nome: %-30s | Ativo: %s\n",
        $row['id'],
        $row['sku'] ?? 'N/A',
        substr($row['name'], 0, 30),
        $row['active'] ? '✅' : '❌'
    );
}

echo "─────────────────────────────────────────────────────────────────\n\n";

// ============================================================================
// STEP 3: Executar ação conforme flag
// ============================================================================
if ($action === '--list') {
    log_info("Modo LIST: Nenhuma modificação foi feita");
    echo "\nUse uma das opções abaixo para corrigir:\n";
    echo "  php scripts/fix-zero-price-products.php --set-min-price\n";
    echo "  php scripts/fix-zero-price-products.php --mark-inactive\n";
    exit(0);
}

if ($action === '--set-min-price') {
    log_info("Atribuindo preço mínimo (R$ 0.01) a " . count($products) . " produtos...");

    $updated = 0;
    foreach ($products as $product) {
        $id = (int)$product['id'];
        if ($db->query("UPDATE products SET price = 0.01 WHERE id = $id")) {
            $updated++;
        }
    }

    log_success("$updated produtos atualizados com preço = R$ 0.01");

    // Validar
    $validate = $db->query("SELECT COUNT(*) as cnt FROM products WHERE price <= 0");
    $row = $validate->fetch_assoc();
    if ((int)$row['cnt'] === 0) {
        log_success("Validação OK: 0 produtos com preço <= 0");
    }
    exit(0);
}

if ($action === '--mark-inactive') {
    log_info("Marcando " . count($products) . " produtos como inativos...");

    $updated = 0;
    foreach ($products as $product) {
        $id = (int)$product['id'];
        if ($db->query("UPDATE products SET active = 0 WHERE id = $id")) {
            $updated++;
        }
    }

    log_success("$updated produtos marcados como inativos (active = 0)");

    // Validar
    $validate = $db->query("SELECT COUNT(*) as cnt FROM products WHERE price <= 0 AND active = 1");
    $row = $validate->fetch_assoc();
    if ((int)$row['cnt'] === 0) {
        log_success("Validação OK: 0 produtos com preço <= 0 e ativos");
    }
    exit(0);
}

// Opção inválida
log_error("Opção desconhecida: $action");
echo "\nOpções disponíveis:\n";
echo "  --list              Listar produtos com price = 0 (padrão)\n";
echo "  --set-min-price     Atribuir price = R$ 0.01\n";
echo "  --mark-inactive     Marcar como inativos (active = 0)\n";
exit(1);
