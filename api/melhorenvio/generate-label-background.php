<?php
declare(strict_types=1);
/**
 * Compra e gera a etiqueta de transporte em background, disparado pelo
 * webhook do Mercado Pago assim que um pedido tem pagamento aprovado
 * (mesmo padrão non-blocking usado por api/webhook-post-processor.php).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$envFile = __DIR__ . '/../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . trim($value));
            }
        }
    }
}

require_once __DIR__ . '/../../includes/melhorenvio-label.php';

$orderPath = $argv[1] ?? '';
if ($orderPath === '' || !is_file($orderPath)) {
    fwrite(STDERR, "Uso: php generate-label-background.php ORDER_JSON_PATH\n");
    exit(1);
}

$order = json_decode((string)file_get_contents($orderPath), true);
if (!is_array($order)) {
    fwrite(STDERR, "Pedido inválido: $orderPath\n");
    exit(1);
}

$result = svml_purchase_and_generate_label($order);

if ($result['ok']) {
    echo "Etiqueta OK: shipment_id=" . ($result['shipment_id'] ?? '') . " url=" . ($result['label_url'] ?? '') . "\n";
    exit(0);
}

error_log('[melhorenvio-label] falha ao gerar etiqueta: order=' . ($order['order_number'] ?? '') . ' erro=' . ($result['error'] ?? 'unknown') . ' ' . json_encode($result['body'] ?? []));
fwrite(STDERR, "Falha: " . ($result['error'] ?? 'unknown') . "\n");
exit(1);
