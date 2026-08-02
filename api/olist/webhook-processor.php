<?php

/**
 * Processa webhooks da Olist/Tiny.
 * Atualiza produtos, precos, estoque e imagens em tempo real.
 */

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/runtime-env-reader.php';

function log_event(string $action, array $data = []): void
{
    $log = __DIR__ . '/../../logs/olist-webhook-processor.log';
    @mkdir(dirname($log), 0755, true);
    $line = json_encode([
        'timestamp' => date('c'),
        'action' => $action,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

$db = null;
try {
    $host = svre_value('DB_HOST', $root);
    $portValue = svre_value('DB_PORT', $root);
    $user = svre_value(['DB_USER', 'DB_USERNAME'], $root);
    $pass = svre_value(['DB_PASS', 'DB_PASSWORD'], $root);
    $name = svre_value(['DB_NAME', 'DB_DATABASE'], $root);
    $port = ctype_digit($portValue) ? (int)$portValue : 3306;

    if ($host === '') {
        $host = 'localhost';
    }
    if ($name === '' || $user === '') {
        throw new RuntimeException('database_configuration_missing');
    }
    if (!class_exists('mysqli') || !function_exists('mysqli_report')) {
        throw new RuntimeException('mysqli_extension_unavailable');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli($host, $user, $pass, $name, $port);
    if ($db->connect_errno !== 0) {
        throw new RuntimeException('database_connection_failed');
    }
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    log_event('error_db_connect', ['type' => get_class($e)]);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'database_unavailable',
        'message' => 'Banco de dados indisponivel',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!$payload) {
    $db->close();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'empty_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$eventType = (string)($payload['event'] ?? $payload['type'] ?? '');
$resource = (string)($payload['resource'] ?? '');
$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

log_event('webhook_received', ['event' => $eventType, 'resource' => $resource]);

switch ($eventType) {
    case 'product.updated':
    case 'product.price.updated':
    case 'product.stock.updated':
        $sku = trim((string)($data['sku'] ?? ''));
        $price = isset($data['price']) ? (float)$data['price'] : null;
        $stock = isset($data['stock']) ? (int)$data['stock'] : null;

        if ($sku !== '' && ($price !== null || $stock !== null)) {
            $updates = [];
            $params = [];
            if ($price !== null) {
                $updates[] = 'price = ?';
                $params[] = $price;
            }
            if ($stock !== null) {
                $updates[] = 'stock = ?';
                $params[] = $stock;
            }
            $updates[] = 'updated_at = NOW()';

            $sql = 'UPDATE products SET ' . implode(', ', $updates) . ' WHERE sku = ?';
            $params[] = $sku;
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $types = str_repeat('d', count($params) - 1) . 's';
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    log_event('product_updated', ['sku' => $sku, 'price' => $price, 'stock' => $stock]);
                }
                $stmt->close();
            }
        }
        break;

    case 'product.deleted':
        $sku = trim((string)($data['sku'] ?? ''));
        if ($sku !== '') {
            $stmt = $db->prepare('DELETE FROM products WHERE sku = ?');
            if ($stmt) {
                $stmt->bind_param('s', $sku);
                if ($stmt->execute()) {
                    log_event('product_deleted', ['sku' => $sku]);
                }
                $stmt->close();
            }
        }
        break;

    case 'product.images.updated':
        $sku = trim((string)($data['sku'] ?? ''));
        $imageUrl = trim((string)($data['image_url'] ?? ''));
        $imagesCount = isset($data['images_count']) ? (int)$data['images_count'] : 0;

        if ($sku !== '' && $imageUrl !== '') {
            $stmt = $db->prepare('UPDATE products SET image_url = ?, images_count = ?, updated_at = NOW() WHERE sku = ?');
            if ($stmt) {
                $stmt->bind_param('sis', $imageUrl, $imagesCount, $sku);
                if ($stmt->execute()) {
                    log_event('images_updated', ['sku' => $sku, 'count' => $imagesCount]);
                }
                $stmt->close();
            }
        }
        break;
}

$db->close();
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'event' => $eventType], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
