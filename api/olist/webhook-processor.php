<?php
/**
 * Processa webhooks da Olist/Tiny
 * Atualiza produtos, preços, estoque e imagens em tempo real
 */

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', 0);

function log_event($action, $data = []) {
    $log = __DIR__ . '/../../logs/olist-webhook-processor.log';
    @mkdir(dirname($log), 0755, true);
    $line = json_encode([
        'timestamp' => date('c'),
        'action' => $action,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($log, $line, FILE_APPEND);
}

// Carregar env
$env_file = __DIR__ . '/../../.env';
if (is_file($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim(trim($v), '"\''));
    }
}

// Tentar banco de dados
$db = null;
try {
    $host = getenv('DB_HOST') ?: 'localhost';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $name = getenv('DB_NAME') ?: 'shopvivaliz';

    $db = @new mysqli($host, $user, $pass, $name);
    if (!$db || $db->connect_error) {
        throw new Exception("DB Connection failed: " . ($db->connect_error ?? 'Unknown error'));
    }
    $db->set_charset('utf8mb4');
} catch (Exception $e) {
    log_event('error_db_connect', [
        'message' => $e->getMessage(),
        'errno' => $db->connect_errno ?? -1,
        'host' => $host ?? 'unknown',
        'name' => $name ?? 'unknown'
    ]);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'database_unavailable',
        'message' => 'Banco de dados indisponível'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Receber webhook
$payload = json_decode((string)file_get_contents('php://input'), true) ?: [];

if (!$payload) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'empty_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$event_type = $payload['event'] ?? $payload['type'] ?? '';
$resource = $payload['resource'] ?? '';
$data = $payload['data'] ?? [];

log_event('webhook_received', ['event' => $event_type, 'resource' => $resource]);

// Processar por tipo de evento
switch ($event_type) {
    case 'product.updated':
    case 'product.price.updated':
    case 'product.stock.updated':
        // Atualizar preço/estoque do produto
        $sku = $data['sku'] ?? '';
        $price = isset($data['price']) ? (float)$data['price'] : null;
        $stock = isset($data['stock']) ? (int)$data['stock'] : null;

        if ($sku && ($price !== null || $stock !== null)) {
            $updates = [];
            $params = [];
            if ($price !== null) {
                $updates[] = "price = ?";
                $params[] = $price;
            }
            if ($stock !== null) {
                $updates[] = "stock = ?";
                $params[] = $stock;
            }
            $updates[] = "updated_at = NOW()";

            $sql = "UPDATE products SET " . implode(", ", $updates) . " WHERE sku = ?";
            $params[] = $sku;

            $stmt = $db->prepare($sql);
            $types = str_repeat('d', count($params) - 1) . 's'; // d=double, s=string
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                log_event('product_updated', ['sku' => $sku, 'price' => $price, 'stock' => $stock]);
            }
        }
        break;

    case 'product.deleted':
        // Remover produto
        $sku = $data['sku'] ?? '';
        if ($sku) {
            $stmt = $db->prepare("DELETE FROM products WHERE sku = ?");
            $stmt->bind_param('s', $sku);
            if ($stmt->execute()) {
                log_event('product_deleted', ['sku' => $sku]);
            }
        }
        break;

    case 'product.images.updated':
        // Atualizar imagens
        $sku = $data['sku'] ?? '';
        $image_url = $data['image_url'] ?? '';
        $images_count = isset($data['images_count']) ? (int)$data['images_count'] : 0;

        if ($sku && $image_url) {
            $stmt = $db->prepare("UPDATE products SET image_url = ?, images_count = ?, updated_at = NOW() WHERE sku = ?");
            $stmt->bind_param('sis', $image_url, $images_count, $sku);
            if ($stmt->execute()) {
                log_event('images_updated', ['sku' => $sku, 'count' => $images_count]);
            }
        }
        break;
}

$db->close();

// Responder OK
http_response_code(200);
echo json_encode(['ok' => true, 'event' => $event_type], JSON_UNESCAPED_UNICODE);
?>
