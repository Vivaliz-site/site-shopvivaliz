<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

require_once __DIR__ . '/../../config/database.php';

function full_sync_json(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function full_sync_price(float|int|string|null $value): float
{
    if ($value === null) return 0.0;
    $normalized = trim(str_replace(['R$', ' '], '', (string)$value));
    if ($normalized === '') return 0.0;
    $normalized = str_replace(['.', ','], ['', '.'], $normalized);
    if (!is_numeric($normalized)) return 0.0;
    return round((float)$normalized, 2);
}

try {
    $db = Database::getInstance()->getConnection();

    $tableCheck = $db->query("SHOW TABLES LIKE 'products'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        full_sync_json(500, ['ok' => false, 'erro' => 'Tabela products não existe']);
    }

    $before = (int)($db->query("SELECT COUNT(*) AS total FROM products")->fetch_assoc()['total'] ?? 0);

    $sourcePath = dirname(__DIR__) . '/catalog/fallback-products.json';
    $items = is_file($sourcePath) ? json_decode((string)file_get_contents($sourcePath), true) : [];
    if (!is_array($items) || !$items) {
        full_sync_json(500, ['ok' => false, 'erro' => 'Fonte de catálogo indisponível']);
    }

    $selectExisting = $db->prepare('SELECT price FROM products WHERE sku = ? LIMIT 1');
    $stmt = $db->prepare(
        'INSERT INTO products (sku, name, description, price, stock, image_url, active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            price = IF(VALUES(price) > 0, VALUES(price), products.price),
            stock = VALUES(stock),
            image_url = VALUES(image_url),
            active = 1,
            updated_at = NOW()'
    );
    if (!$selectExisting || !$stmt) {
        full_sync_json(500, ['ok' => false, 'erro' => 'Falha ao preparar statements', 'db_error' => $db->error]);
    }

    $synced = 0;
    $skipped = 0;
    $priceLogs = [];

    foreach ($items as $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }

        $sku = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if ($sku === '' && $name === '') {
            $skipped++;
            continue;
        }

        $description = trim((string)($row['description'] ?? ''));
        $price = full_sync_price($row['preco'] ?? ($row['price'] ?? 0));
        echo "SYNC SKU: {$sku} | PRICE: {$price}\n";
        if ($price <= 0) {
            $skipped++;
            continue;
        }

        $stock = max(0, (int)($row['stock'] ?? 0));
        $image = trim((string)($row['image_url'] ?? ($row['images'][0] ?? '')));

        $existingPrice = null;
        $selectExisting->bind_param('s', $sku);
        $selectExisting->execute();
        $result = $selectExisting->get_result();
        if ($result && ($existing = $result->fetch_assoc())) {
            $existingPrice = (float)($existing['price'] ?? 0);
        }

        $finalPrice = $price > 0 ? $price : (float)($existingPrice ?? 0);
        $stmt->bind_param('sssdis', $sku, $name, $description, $finalPrice, $stock, $image);
        if ($stmt->execute()) {
            $synced++;
            $priceLogs[] = [
                'sku' => $sku,
                'old_price' => $existingPrice,
                'new_price' => $price,
                'stored_price' => $finalPrice,
            ];
        }
    }

    $selectExisting->close();
    $stmt->close();

    $after = (int)($db->query("SELECT COUNT(*) AS total FROM products")->fetch_assoc()['total'] ?? 0);

    $logDir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(
        $logDir . '/full-sync-prices.log',
        json_encode([
            'timestamp' => date('c'),
            'updated_prices' => $priceLogs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    full_sync_json(200, [
        'ok' => true,
        'antes' => $before,
        'sincronizados' => $synced,
        'ignorados' => $skipped,
        'depois' => $after,
        'precos_atualizados' => count($priceLogs),
        'timestamp' => date('c'),
    ]);
} catch (Throwable $e) {
    full_sync_json(500, [
        'ok' => false,
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
    ]);
}
