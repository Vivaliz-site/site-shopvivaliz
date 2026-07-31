<?php
/**
 * VARREDURA PROFUNDA DO SITE - Encontrar TODOS os erros
 */

require_once __DIR__ . '/../config/database.php';

$errors = [];
$warnings = [];
$issues = [];

echo "🔍 VARREDURA PROFUNDA DO SITE\n";
echo "====================================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // ============================================================================
    // 1. PRODUTOS SEM PREÇO
    // ============================================================================
    echo "[1] Checando produtos sem preço...\n";
    $result = $db->query("SELECT id, sku, name, price FROM products WHERE price IS NULL OR price = 0 OR price = ''");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $count++;
            if ($count <= 5) {
                $errors[] = "PRODUTO SEM PREÇO: SKU={$row['sku']}, Name={$row['name']}, ID={$row['id']}";
            }
        }
        echo "   ❌ ENCONTRADO: {$result->num_rows} produtos sem preço\n";
    } else {
        echo "   ✅ OK: Todos produtos têm preço\n";
    }
    
    // ============================================================================
    // 2. PRODUTOS SEM IMAGEM
    // ============================================================================
    echo "[2] Checando produtos sem imagem...\n";
    $result = $db->query("SELECT id, sku, name, image_url FROM products WHERE image_url IS NULL OR image_url = ''");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $count++;
            if ($count <= 5) {
                $errors[] = "PRODUTO SEM IMAGEM: SKU={$row['sku']}, Name={$row['name']}, ID={$row['id']}";
            }
        }
        echo "   ❌ ENCONTRADO: {$result->num_rows} produtos sem imagem\n";
    } else {
        echo "   ✅ OK: Todos produtos têm imagem\n";
    }
    
    // ============================================================================
    // 3. PRODUTOS SEM DESCRIÇÃO
    // ============================================================================
    echo "[3] Checando produtos sem descrição...\n";
    $result = $db->query("SELECT id, sku, name, description FROM products WHERE description IS NULL OR description = ''");
    if ($result && $result->num_rows > 0) {
        echo "   ⚠️  AVISO: {$result->num_rows} produtos sem descrição\n";
    } else {
        echo "   ✅ OK: Todos produtos têm descrição\n";
    }
    
    // ============================================================================
    // 4. PRODUTOS SEM SKU
    // ============================================================================
    echo "[4] Checando produtos sem SKU...\n";
    $result = $db->query("SELECT id, name FROM products WHERE sku IS NULL OR sku = ''");
    if ($result && $result->num_rows > 0) {
        echo "   ❌ ENCONTRADO: {$result->num_rows} produtos sem SKU\n";
    } else {
        echo "   ✅ OK: Todos produtos têm SKU\n";
    }
    
    // ============================================================================
    // 5. PRODUTOS DESATIVADOS
    // ============================================================================
    echo "[5] Checando produtos desativados (active = 0)...\n";
    $result = $db->query("SELECT COUNT(*) as c FROM products WHERE active = 0 OR active IS NULL");
    $row = $result->fetch_assoc();
    if ($row['c'] > 0) {
        $warnings[] = "AVISO: {$row['c']} produtos desativados";
        echo "   ⚠️  AVISO: {$row['c']} produtos desativados\n";
    } else {
        echo "   ✅ OK: Todos produtos ativados\n";
    }
    
    // ============================================================================
    // 6. STOCK = 0
    // ============================================================================
    echo "[6] Checando produtos com stock = 0...\n";
    $result = $db->query("SELECT COUNT(*) as c FROM products WHERE stock = 0 OR stock IS NULL");
    $row = $result->fetch_assoc();
    if ($row['c'] > 0) {
        $warnings[] = "AVISO: {$row['c']} produtos sem estoque";
        echo "   ⚠️  AVISO: {$row['c']} produtos com stock = 0\n";
    } else {
        echo "   ✅ OK: Todos produtos têm stock\n";
    }
    
    // ============================================================================
    // 7. TOTAIS DE PRODUTOS
    // ============================================================================
    echo "[7] Total de produtos...\n";
    $result = $db->query("SELECT COUNT(*) as total, COUNT(CASE WHEN active = 1 THEN 1 END) as active FROM products");
    $row = $result->fetch_assoc();
    echo "   Total: {$row['total']}, Ativos: {$row['active']}\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}

// ============================================================================
// 8. VERIFICAR CONFIGURAÇÕES
// ============================================================================
echo "\n[8] Checando configurações...\n";
$configFiles = [
    '.env' => 'Variáveis de ambiente',
    'config/constants.php' => 'Constantes da loja',
];

foreach ($configFiles as $file => $desc) {
    if (file_exists(__DIR__ . '/../' . $file)) {
        echo "   ✅ $desc ($file) existe\n";
    } else {
        $errors[] = "ARQUIVO FALTANDO: $file ($desc)";
        echo "   ❌ $desc ($file) NÃO ENCONTRADO\n";
    }
}

// ============================================================================
// 9. VERIFICAR DIRETÓRIOS CRÍTICOS
// ============================================================================
echo "\n[9] Checando diretórios críticos...\n";
$dirs = [
    'storage/orders' => 'Armazenamento de pedidos',
    'logs' => 'Logs',
    'uploads' => 'Uploads',
    'cache' => 'Cache',
];

foreach ($dirs as $dir => $desc) {
    if (is_dir(__DIR__ . '/../' . $dir)) {
        echo "   ✅ $desc ($dir) existe\n";
    } else {
        $warnings[] = "DIRETÓRIO FALTANDO: $dir ($desc)";
        echo "   ⚠️  $desc ($dir) não existe\n";
    }
}

// ============================================================================
// RESUMO FINAL
// ============================================================================
echo "\n====================================\n";
echo "📊 RESUMO DA VARREDURA\n";
echo "====================================\n";
echo "🔴 Erros críticos: " . count($errors) . "\n";
echo "🟡 Avisos: " . count($warnings) . "\n";

if (count($errors) > 0) {
    echo "\n🔴 ERROS ENCONTRADOS:\n";
    foreach ($errors as $error) {
        echo "   - $error\n";
    }
}

if (count($warnings) > 0) {
    echo "\n🟡 AVISOS:\n";
    foreach ($warnings as $warning) {
        echo "   - $warning\n";
    }
}

echo "\n";
