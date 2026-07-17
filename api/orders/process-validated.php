<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/order-request-context.php';
require_once dirname(__DIR__, 2) . '/includes/order-idempotency.php';
require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__, 2) . '/api/emails/send-order-notification.php';
require_once dirname(__DIR__, 2) . '/includes/tiny-order-push.php';

function svop_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svop_root(): string
{
    return dirname(__DIR__, 2);
}

function svop_order_dir(): string
{
    $preferred = svop_root() . '/storage/orders';
    if ((is_dir($preferred) || @mkdir($preferred, 0755, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-orders';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) {
        return $fallback;
    }

    return '';
}

function svop_payment_method(string $value): string
{
    $normalized = strtolower(trim($value));
    $allowed = ['pix', 'boleto', 'whatsapp', 'transferencia', 'mercado_pago', 'pagarme'];
    return in_array($normalized, $allowed, true) ? $normalized : 'pix';
}

function svop_payment_label(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto bancario',
        'whatsapp' => 'WhatsApp',
        'transferencia' => 'Transferencia bancaria',
        'mercado_pago' => 'Mercado Pago',
        'pagarme' => 'Pagar.me',
        default => 'PIX',
    };
}

function svop_payment_instructions(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto emitido pelo Mercado Pago com linha digitavel e link seguro.',
        'whatsapp' => 'Pagamento e frete serao alinhados pelo atendimento no WhatsApp.',
        'transferencia' => 'Dados bancarios serao enviados pela equipe apos confirmacao do frete.',
        'mercado_pago' => 'Pagamento processado no ambiente seguro do Mercado Pago.',
        'pagarme' => 'Link de pagamento do Pagar.me sera enviado apos confirmacao do frete.',
        default => 'Pagamento via PIX com confirmacao apos validacao do pedido.',
    };
}

function svop_append_log(array $order): void
{
    $dir = svop_root() . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $entry = [
        'id' => $order['order_number'] ?? '',
        'timestamp' => $order['created_at'] ?? date('c'),
        'cliente' => [
            'nome' => $order['customer']['name'] ?? '',
            'email' => $order['customer']['email'] ?? '',
            'telefone' => $order['customer']['phone'] ?? '',
            'endereco' => $order['customer']['address'] ?? '',
            'cep' => $order['customer']['cep'] ?? '',
        ],
        'items' => $order['items'] ?? [],
        'payment_method' => $order['payment_method'] ?? 'pix',
        'status' => 'pendente_atendimento',
        'source' => 'checkout_site_api',
        'shipping_total' => round((float)($order['shipping_total'] ?? 0), 2),
        'shipping_label' => (string)($order['shipping_label'] ?? ''),
        'tiny_order_id' => (string)($order['tiny_order_id'] ?? ''),
        'tiny_push' => (string)($order['tiny_push'] ?? ''),
        'total' => round((float)($order['total'] ?? 0), 2),
    ];

    @file_put_contents(
        $dir . '/pedidos.jsonl',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function svop_load_runtime_secrets(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = svop_root() . '/config/runtime-secrets.php';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $secrets = require $path;
    if (!is_array($secrets)) {
        return;
    }

    foreach ($secrets as $key => $value) {
        if (!is_string($key) || $key === '' || getenv($key) !== false) {
            continue;
        }
        $stringValue = is_scalar($value) ? (string)$value : '';
        putenv($key . '=' . $stringValue);
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
    }
}

function svop_tiny_env(string ...$keys): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        svop_load_runtime_secrets();

        $envFile = svop_root() . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if ($k !== '' && getenv($k) === false) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }

        $tokensFile = svop_root() . '/storage/private/tokens.json';
        if (is_file($tokensFile)) {
            $tokens = json_decode((string)file_get_contents($tokensFile), true) ?: [];
            foreach ($tokens as $k => $v) {
                if (is_string($k) && is_string($v) && getenv($k) === false) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }
    }

    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        if (isset($_ENV[$k]) && is_string($_ENV[$k]) && $_ENV[$k] !== '') {
            return $_ENV[$k];
        }
    }

    return '';
}

function svop_tiny_credentials_configured(): bool
{
    return svop_tiny_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN') !== ''
        && svop_tiny_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID') !== ''
        && svop_tiny_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET') !== '';
}

function svop_tiny_get_token(): string
{
    $refresh = svop_tiny_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN');
    $clientId = svop_tiny_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID');
    $clientSecret = svop_tiny_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET');
    if ($refresh === '' || $clientId === '' || $clientSecret === '') {
        return '';
    }

    $ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status !== 200) {
        return '';
    }
    $json = json_decode(is_string($body) ? $body : '', true);
    return is_array($json) ? (string)($json['access_token'] ?? '') : '';
}

function svop_push_order_tiny(array $order): ?string
{
    $token = svop_tiny_get_token();
    if ($token === '') {
        return null;
    }

    $customer = $order['customer'] ?? [];
    $cep = preg_replace('/\D/', '', (string)($customer['cep'] ?? ''));
    $paymentMethod = svop_payment_label((string)($order['payment_method'] ?? 'pix'));
    $notes = trim((string)($order['notes'] ?? ''));
    $obs = trim("Forma de pagamento: {$paymentMethod}\n" . $notes);

    $payload = [
        'numeroPedido' => $order['order_number'],
        'situacao' => ['id' => 1],
        'cliente' => [
            'nome' => $customer['name'] ?? '',
            'email' => $customer['email'] ?? '',
            'fone' => $customer['phone'] ?? '',
            'enderecos' => [[
                'tipo' => 'E',
                'cep' => $cep,
                'endereco' => $customer['address'] ?? '',
                'cidade' => '',
                'uf' => '',
            ]],
        ],
        'itens' => array_map(static fn(array $item): array => [
            'codigo' => $item['sku'],
            'descricao' => $item['name'],
            'quantidade' => $item['quantity'],
            'valor' => $item['price'],
        ], $order['items'] ?? []),
        'obs' => $obs,
    ];

    $ch = curl_init('https://api.tiny.com.br/public-api/v3/pedidos');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ShopVivaliz/3.0',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status !== 200 && $status !== 201) {
        throw new RuntimeException("Tiny POST /pedidos HTTP $status: " . substr(is_string($body) ? $body : '', 0, 200));
    }

    $json = json_decode(is_string($body) ? $body : '', true);
    return (string)($json['id'] ?? $json['idPedido'] ?? '');
}

$body = svorc_body();
$items = svorc_items();
$idempotencyKey = svoi_key($body, $items);
if ($body === [] || $items === []) {
    svop_json(500, ['ok' => false, 'error' => 'validated_context_missing']);
}

$name = trim((string)($body['customer_name'] ?? ''));
$email = trim((string)($body['customer_email'] ?? ''));
$phone = trim((string)($body['customer_phone'] ?? ''));
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
$cpf = preg_replace('/\D+/', '', (string)($body['cpf'] ?? ''));
$streetName = trim((string)($body['street_name'] ?? $address));
$streetNumber = trim((string)($body['street_number'] ?? ''));
$neighborhood = trim((string)($body['neighborhood'] ?? ''));
$city = trim((string)($body['city'] ?? ''));
$state = strtoupper(trim((string)($body['state'] ?? '')));
$notes = trim((string)($body['notes'] ?? ''));
$paymentMethod = svop_payment_method((string)($body['payment_method'] ?? 'pix'));
$deviceId = trim((string)($body['device_id'] ?? ''));

if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($address) > 300 || strlen($streetNumber) > 30 || strlen($neighborhood) > 120 || strlen($city) > 120 || strlen($state) > 2 || strlen($notes) > 1000 || strlen($deviceId) > 255) {
    svoi_release($idempotencyKey);
    svop_json(422, ['ok' => false, 'error' => 'field_too_long']);
}
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || strlen($cep) !== 8 || $address === '') {
    svoi_release($idempotencyKey);
    svop_json(422, ['ok' => false, 'error' => 'missing_required_fields']);
}
if ($paymentMethod === 'boleto' && (!svmp_validate_cpf($cpf) || $streetName === '' || $streetNumber === '' || $neighborhood === '' || $city === '' || strlen($state) !== 2)) {
    svoi_release($idempotencyKey);
    svop_json(422, ['ok' => false, 'error' => 'boleto_payer_fields_invalid', 'message' => 'Preencha CPF e endereco completo para emitir o boleto.']);
}

$shippingTotal = round(max(0.0, (float)($body['shipping_total'] ?? 0)), 2);
$shippingLabel = trim((string)($body['shipping_label'] ?? ''));
$shippingService = trim((string)($body['shipping_service'] ?? ''));
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $cep));

$itemsTotal = 0.0;
$cleanItems = [];
foreach ($items as $item) {
    $price = round((float)($item['price'] ?? 0), 2);
    $quantity = (int)($item['quantity'] ?? 0);
    $itemsTotal += $price * $quantity;
    $cleanItems[] = [
        'sku' => (string)($item['sku'] ?? ''),
        'name' => (string)($item['name'] ?? ''),
        'quantity' => $quantity,
        'price' => $price,
        'olist_product_id' => (string)($item['olist_product_id'] ?? ''),
    ];
}

$orderNumber = 'SV' . date('YmdHis') . random_int(100, 999);
$paymentSessionToken = in_array($paymentMethod, ['boleto', 'mercado_pago'], true)
    ? bin2hex(random_bytes(32))
    : '';
$record = [
    'order_number' => $orderNumber,
    'device_id' => $deviceId,
    'status' => 'pending_confirmation',
    'customer' => [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'cep' => $cep,
        'address' => $address,
        'cpf' => $cpf,
        'street_name' => $streetName,
        'street_number' => $streetNumber,
        'neighborhood' => $neighborhood,
        'city' => $city,
        'state' => $state,
    ],
    'items' => $cleanItems,
    'items_total' => round($itemsTotal, 2),
    'shipping_total' => $shippingTotal,
    'shipping_label' => $shippingLabel,
    'shipping_service' => $shippingService,
    'shipping_cep' => $shippingCep,
    'total' => round($itemsTotal + $shippingTotal, 2),
    'payment_method' => $paymentMethod,
    'payment_label' => svop_payment_label($paymentMethod),
    'notes' => $notes,
    'created_at' => date('c'),
    'source' => 'site_checkout_validated',
    'idempotency_key_hash' => hash('sha256', $idempotencyKey),
    'payment_session_hash' => $paymentSessionToken !== '' ? hash('sha256', $paymentSessionToken) : '',
];

$dir = svop_order_dir();
if ($dir === '') {
    svoi_release($idempotencyKey);
    svop_json(500, ['ok' => false, 'error' => 'order_storage_unavailable']);
}

$path = $dir . '/' . $orderNumber . '.json';
if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    svoi_release($idempotencyKey);
    svop_json(500, ['ok' => false, 'error' => 'order_write_failed']);
}

$tinyOrderId = null;
$tinyPushStatus = 'missing_credentials';
if (svop_tiny_credentials_configured()) {
    $tinyPushStatus = 'token_unavailable';
    try {
        $tinyOrderId = svop_push_order_tiny($record);
        if ($tinyOrderId) {
            $tinyPushStatus = 'ok';
            $record['tiny_order_id'] = $tinyOrderId;
        }
    } catch (Throwable $e) {
        $tinyPushStatus = $e->getMessage();
        error_log('[OrderValidated] Tiny push error: ' . $e->getMessage());
    }
}

$record['tiny_push'] = $tinyPushStatus;
file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
svop_append_log($record);

// Disparar email de confirmação do pedido
try {
    $emailSent = svem_send_order_email($record, 'order_created');
    $record['confirmation_email_sent'] = $emailSent;
} catch (Throwable $e) {
    error_log('[OrderValidated] Email send error: ' . $e->getMessage());
    $record['confirmation_email_sent'] = false;
}

$response = [
    'ok' => true,
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'payment_method' => $paymentMethod,
    'payment_label' => $record['payment_label'],
    'message' => 'Pedido registrado para confirmacao manual de frete e pagamento.',
    'payment_instructions' => svop_payment_instructions($paymentMethod),
    'storage' => str_contains($dir, 'shopvivaliz-orders') ? 'fallback_temp' : 'storage_orders',
    'tiny_order_id' => $tinyOrderId,
    'tiny_push' => $tinyPushStatus,
    'subtotal' => round($itemsTotal, 2),
    'shipping_total' => $shippingTotal,
    'shipping_label' => $shippingLabel,
    'total' => $record['total'],
];
if ($paymentSessionToken !== '') {
    $response['payment_session_token'] = $paymentSessionToken;
}
svop_json(200, $response);
