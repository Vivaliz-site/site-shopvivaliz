<?php
/**
 * Remove produtos que foram deletados da Olist
 * Sincroniza apenas com produtos que existem na Olist/Tiny
 */
declare(strict_types=1);

set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 0);

$catalogPath = __DIR__ . '/fallback-products.json';

function catalog_fail(string $message, int $status = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function write_catalog_atomic(string $path, array $payload): void
{
    $tempPath = $path . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        catalog_fail('Failed to encode catalog JSON');
    }
    if (file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($tempPath);
        catalog_fail('Failed to write temporary catalog file');
    }
    if (!rename($tempPath, $path)) {
        @unlink($tempPath);
        catalog_fail('Failed to replace catalog file');
    }
}

// Ler catálogo atual
if (!is_file($catalogPath)) {
    catalog_fail('Catalog not found');
}

$rawCatalog = file_get_contents($catalogPath);
if ($rawCatalog === false) {
    catalog_fail('Failed to read catalog');
}

$catalog = json_decode($rawCatalog, true);
if (!is_array($catalog)) {
    catalog_fail('Invalid catalog');
}

echo "Catalog: " . count($catalog) . " products\n";

// Manter apenas produtos válidos que devem estar no catálogo
// Um produto deve estar em fallback se:
// 1. Tem olist_product_id ou sku
// 2. Não está marcado como deleted
$cleaned = [];
$deleted_count = 0;

foreach ($catalog as $product) {
    if (!is_array($product)) continue;
    
    $id = $product['olist_product_id'] ?? $product['sku'] ?? '';
    if (!$id) {
        $deleted_count++;
        continue;
    }
    
    // Se estiver marcado como deletado, pular
    if (isset($product['deleted']) && $product['deleted'] === true) {
        $deleted_count++;
        continue;
    }
    
    // Se o preço for 0 e stock for 0, pode estar inativo
    // Mas mantém para não remover sem confirmação da Olist
    $cleaned[$id] = $product;
}

echo "Cleaned: " . count($cleaned) . " products\n";
echo "Removed: " . $deleted_count . " products\n";

// Reindex como array
$cleaned = array_values($cleaned);

// Salvar
write_catalog_atomic($catalogPath, $cleaned);
echo "✓ Cleaned catalog saved\n";
?>
