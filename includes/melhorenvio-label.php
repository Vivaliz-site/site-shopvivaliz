<?php
declare(strict_types=1);

/**
 * Compra e geração automática de etiqueta de transporte via Melhor Envio,
 * disparada quando um pedido tem o pagamento aprovado. Reusa o OAuth de
 * includes/melhorenvio-oauth.php (mesmo token usado para cotação em
 * api/melhorenvio/shipping-check-v2.php).
 *
 * Sequência real da API (confirmada em docs.melhorenvio.com.br/reference):
 *   1. POST /me/cart               -> adiciona o frete ao carrinho, retorna id
 *   2. POST /me/shipment/checkout  -> compra (debita saldo da carteira)
 *   3. POST /me/shipment/generate  -> gera a etiqueta
 *   4. POST /me/shipment/print     -> retorna a URL do PDF para impressão
 */

require_once __DIR__ . '/melhorenvio-oauth.php';
require_once __DIR__ . '/catalog-runtime.php';
require_once __DIR__ . '/pdo-database.php';
require_once __DIR__ . '/account-schema.php';
require_once __DIR__ . '/tiny-order-push.php';

function svml_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
    }
    return '';
}

function svml_ensure_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = sv_pdo();
    $existing = [];
    foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() as $row) {
        $existing[$row['Field']] = true;
    }
    $alterations = [
        'label_url' => 'ALTER TABLE orders ADD COLUMN label_url VARCHAR(500) NULL',
        'melhorenvio_shipment_id' => 'ALTER TABLE orders ADD COLUMN melhorenvio_shipment_id VARCHAR(64) NULL',
        'label_status' => 'ALTER TABLE orders ADD COLUMN label_status VARCHAR(30) NULL',
        'tiny_dispatch_status' => 'ALTER TABLE orders ADD COLUMN tiny_dispatch_status VARCHAR(40) NULL',
        'tiny_dispatch_error' => 'ALTER TABLE orders ADD COLUMN tiny_dispatch_error VARCHAR(500) NULL',
        'tiny_dispatch_updated_at' => 'ALTER TABLE orders ADD COLUMN tiny_dispatch_updated_at DATETIME NULL',
    ];
    foreach ($alterations as $column => $sql) {
        if (!isset($existing[$column])) {
            $pdo->exec($sql);
        }
    }
}

/**
 * Endereço de origem (remetente) -- usa os dados reais da empresa já
 * cadastrados em config/company-profile.php (mesmo arquivo usado pelo painel
 * admin/company-profile.php), com variáveis MELHORENVIO_FROM_* no .env como
 * override opcional caso precise divergir do endereço fiscal.
 */
function svml_from_address(): array
{
    static $company = null;
    if ($company === null) {
        $path = dirname(__DIR__) . '/config/company-profile.php';
        $company = is_file($path) ? (require $path) : [];
        if (!is_array($company)) {
            $company = [];
        }
    }

    return [
        'name' => svml_env('MELHORENVIO_FROM_NAME') ?: (string)($company['fantasy_name'] ?? 'ShopVivaliz'),
        'document' => preg_replace('/\D+/', '', svml_env('MELHORENVIO_FROM_DOCUMENT', 'MELHORENVIO_FROM_CNPJ') ?: (string)($company['cnpj'] ?? '')),
        'email' => svml_env('MELHORENVIO_FROM_EMAIL') ?: (string)($company['email'] ?? 'atendimento@shopvivaliz.com.br'),
        'phone' => preg_replace('/\D+/', '', svml_env('MELHORENVIO_FROM_PHONE') ?: (string)($company['phone'] ?? '')),
        'address' => svml_env('MELHORENVIO_FROM_ADDRESS') ?: (string)($company['address'] ?? ''),
        'complement' => svml_env('MELHORENVIO_FROM_COMPLEMENT') ?: (string)($company['complement'] ?? ''),
        'number' => svml_env('MELHORENVIO_FROM_NUMBER') ?: (string)($company['number'] ?? ''),
        'district' => svml_env('MELHORENVIO_FROM_DISTRICT') ?: (string)($company['neighborhood'] ?? ''),
        'city' => svml_env('MELHORENVIO_FROM_CITY') ?: (string)($company['city'] ?? ''),
        'state_abbr' => strtoupper(svml_env('MELHORENVIO_FROM_STATE') ?: (string)($company['state'] ?? '')),
        'postal_code' => preg_replace('/\D+/', '', svml_env('MELHORENVIO_FROM_POSTAL_CODE', 'SHOPVIVALIZ_FROM_POSTAL_CODE') ?: (string)($company['zipcode'] ?? '')) ?: '35501236',
    ];
}

function svml_from_address_complete(array $from): bool
{
    foreach (['name', 'document', 'address', 'number', 'district', 'city', 'state_abbr', 'postal_code'] as $field) {
        if (trim((string)($from[$field] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

function svml_catalog_row(string $sku, string $olistId): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = svcr_products();
    }
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $rowSku = trim((string)($row['sku'] ?? ''));
        $rowId = trim((string)($row['id'] ?? $row['olist_product_id'] ?? ''));
        if (($sku !== '' && strcasecmp($rowSku, $sku) === 0) || ($olistId !== '' && $rowId === $olistId)) {
            return $row;
        }
    }
    return [];
}

function svml_number(array $row, array $keys, float $fallback): float
{
    foreach ($keys as $key) {
        $value = (float)($row[$key] ?? 0);
        if ($value > 0) return $value;
    }
    return $fallback;
}

function svml_post(string $url, array $payload, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: ShopVivaliz (contato@shopvivaliz.com.br)',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_array($body) ? $body : [],
        'error' => $err,
    ];
}

/**
 * Compra e gera a etiqueta para um pedido já pago. Idempotente: se o pedido
 * já tiver melhorenvio_shipment_id gravado, não repete a compra.
 *
 * @param array $order Estrutura do JSON do pedido (customer/items/shipping_service).
 * @return array{ok:bool, error?:string, label_url?:string, shipment_id?:string}
 */
function svml_purchase_and_generate_label(array $order): array
{
    svml_ensure_columns();

    $orderNumber = trim((string)($order['order_number'] ?? ''));
    if ($orderNumber === '') {
        return ['ok' => false, 'error' => 'missing_order_number'];
    }

    $pdo = sv_pdo();
    $existing = $pdo->prepare('SELECT id, melhorenvio_shipment_id, label_url FROM orders WHERE order_number = :n LIMIT 1');
    $existing->execute([':n' => $orderNumber]);
    $row = $existing->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'order_not_found_in_db'];
    }
    if (!empty($row['melhorenvio_shipment_id']) && !empty($row['label_url'])) {
        return ['ok' => true, 'label_url' => (string)$row['label_url'], 'shipment_id' => (string)$row['melhorenvio_shipment_id'], 'already_generated' => true];
    }
    $orderId = (int)$row['id'];

    $serviceId = trim((string)($order['shipping_service'] ?? ''));
    if ($serviceId === '' || !ctype_digit($serviceId)) {
        return ['ok' => false, 'error' => 'missing_shipping_service'];
    }

    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $cep = preg_replace('/\D+/', '', (string)($customer['cep'] ?? ''));
    if (strlen($cep) !== 8) {
        return ['ok' => false, 'error' => 'invalid_destination_cep'];
    }

    $from = svml_from_address();
    if (!svml_from_address_complete($from)) {
        return ['ok' => false, 'error' => 'sender_address_not_configured', 'message' => 'Configure MELHORENVIO_FROM_* no .env (nome, documento, endereço completo) para permitir a compra automática da etiqueta.'];
    }

    $token = me_current_access_token() ?: svml_env('MELHORENVIO_ACCESS_TOKEN', 'SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN', 'MELHORENVIO_API_KEY');
    if ($token === null || $token === '') {
        return ['ok' => false, 'error' => 'missing_access_token'];
    }

    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    if ($items === []) {
        return ['ok' => false, 'error' => 'empty_items'];
    }

    $products = [];
    $volumes = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $sku = trim((string)($item['sku'] ?? ''));
        $olistId = trim((string)($item['olist_product_id'] ?? ''));
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $price = max(0.01, (float)($item['price'] ?? 0));
        $catalogRow = svml_catalog_row($sku, $olistId);

        $products[] = [
            'name' => (string)($item['name'] ?? 'Produto'),
            'quantity' => (string)$qty,
            'unitary_value' => number_format($price, 2, '.', ''),
        ];
        $volumes[] = [
            'height' => max(1, (int)round(svml_number($catalogRow, ['height', 'altura'], 16))),
            'width' => max(1, (int)round(svml_number($catalogRow, ['width', 'largura'], 16))),
            'length' => max(1, (int)round(svml_number($catalogRow, ['length', 'comprimento'], 16))),
            'weight' => max(0.1, svml_number($catalogRow, ['weight', 'peso'], 1)),
        ];
    }

    $insuranceValue = round((float)($order['items_total'] ?? 0), 2);

    $cartPayload = [
        'service' => (int)$serviceId,
        'from' => $from,
        'to' => [
            'name' => (string)($customer['name'] ?? 'Cliente'),
            'phone' => preg_replace('/\D+/', '', (string)($customer['phone'] ?? '')),
            'email' => (string)($customer['email'] ?? ''),
            'document' => preg_replace('/\D+/', '', (string)($customer['cpf'] ?? '')),
            'address' => (string)($customer['street_name'] ?? $customer['address'] ?? ''),
            'number' => (string)($customer['street_number'] ?? 'S/N'),
            'district' => (string)($customer['neighborhood'] ?? ''),
            'city' => (string)($customer['city'] ?? ''),
            'state_abbr' => strtoupper((string)($customer['state'] ?? '')),
            'postal_code' => $cep,
        ],
        'products' => $products,
        'volumes' => $volumes,
        'options' => [
            'platform' => 'ShopVivaliz',
            'insurance_value' => $insuranceValue,
            'receipt' => false,
            'own_hand' => false,
        ],
    ];

    $base = me_api_base();

    $cartResp = svml_post($base . '/api/v2/me/cart', $cartPayload, $token);
    if (!$cartResp['ok']) {
        svml_mark_label_status($orderId, 'cart_failed');
        return ['ok' => false, 'error' => 'cart_failed', 'status' => $cartResp['status'], 'body' => $cartResp['body']];
    }
    $shipmentId = (string)($cartResp['body']['id'] ?? '');
    if ($shipmentId === '') {
        svml_mark_label_status($orderId, 'cart_no_id');
        return ['ok' => false, 'error' => 'cart_no_id', 'body' => $cartResp['body']];
    }

    $checkoutResp = svml_post($base . '/api/v2/me/shipment/checkout', ['orders' => [$shipmentId]], $token);
    if (!$checkoutResp['ok']) {
        svml_mark_label_status($orderId, 'checkout_failed');
        return ['ok' => false, 'error' => 'checkout_failed', 'status' => $checkoutResp['status'], 'body' => $checkoutResp['body'], 'shipment_id' => $shipmentId];
    }

    $generateResp = svml_post($base . '/api/v2/me/shipment/generate', ['orders' => [$shipmentId]], $token);
    if (!$generateResp['ok']) {
        svml_mark_label_status($orderId, 'generate_failed');
        return ['ok' => false, 'error' => 'generate_failed', 'status' => $generateResp['status'], 'body' => $generateResp['body'], 'shipment_id' => $shipmentId];
    }

    $printResp = svml_post($base . '/api/v2/me/shipment/print', ['orders' => [$shipmentId], 'mode' => 'private'], $token);
    $labelUrl = (string)($printResp['body']['url'] ?? '');

    $update = $pdo->prepare(
        'UPDATE orders SET melhorenvio_shipment_id = :sid, label_url = :url, label_status = :status, updated_at = NOW() WHERE id = :id'
    );
    $update->execute([
        ':sid' => $shipmentId,
        ':url' => $labelUrl !== '' ? $labelUrl : null,
        ':status' => $labelUrl !== '' ? 'generated' : 'generated_no_print_url',
        ':id' => $orderId,
    ]);

    $dispatchResult = svml_sync_tiny_dispatch($order, [
        'shipment_id' => $shipmentId,
        'label_url' => $labelUrl,
        'codigoRastreamento' => trim((string)($order['tracking_number'] ?? $order['tracking'] ?? '')),
    ]);

    if ($dispatchResult['ok'] ?? false) {
        $dispatchUpdate = $pdo->prepare(
            'UPDATE orders SET tiny_dispatch_status = :status, tiny_dispatch_error = NULL, tiny_dispatch_updated_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $dispatchUpdate->execute([
            ':status' => 'updated',
            ':id' => $orderId,
        ]);
    } else {
        $dispatchSummary = (string)($dispatchResult['error'] ?? 'skipped');
        if (isset($dispatchResult['status'])) {
            $dispatchSummary .= ' HTTP ' . (string)$dispatchResult['status'];
        }
        $dispatchUpdate = $pdo->prepare(
            'UPDATE orders SET tiny_dispatch_status = :status, tiny_dispatch_error = :error, tiny_dispatch_updated_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $dispatchUpdate->execute([
            ':status' => (string)($dispatchResult['error'] ?? 'skipped'),
            ':error' => substr($dispatchSummary, 0, 500),
            ':id' => $orderId,
        ]);
    }

    return [
        'ok' => true,
        'shipment_id' => $shipmentId,
        'label_url' => $labelUrl,
        'tiny_dispatch' => $dispatchResult,
    ];
}

function svml_sync_tiny_dispatch(array $order, array $context = []): array
{
    $tinyOrderId = trim((string)($order['tiny_order_id'] ?? ''));
    if ($tinyOrderId === '') {
        return ['ok' => false, 'error' => 'missing_tiny_order_id'];
    }

    $payload = svtop_tiny_build_dispatch_payload($order, $context);
    if ($payload === []) {
        return ['ok' => false, 'error' => 'dispatch_payload_empty'];
    }

    $token = svtop_tiny_get_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'missing_tiny_token'];
    }

    $res = svtop_tiny_update_dispatch($tinyOrderId, $token, $payload);
    if ($res['status'] === 200 || $res['status'] === 204) {
        return ['ok' => true, 'status' => $res['status'], 'payload' => $payload, 'body' => $res['json']];
    }

    return [
        'ok' => false,
        'error' => 'dispatch_failed',
        'status' => $res['status'],
        'body' => $res['json'] ?: $res['body'],
        'payload' => $payload,
    ];
}

function svml_mark_label_status(int $orderId, string $status): void
{
    try {
        $pdo = sv_pdo();
        $stmt = $pdo->prepare('UPDATE orders SET label_status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $orderId]);
    } catch (Throwable $e) {
        error_log('[melhorenvio-label] failed to mark status: ' . $e->getMessage());
    }
}
