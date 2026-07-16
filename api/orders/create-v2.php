<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

function svo_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svo_root(): string
{
    return dirname(__DIR__, 2);
}

/** Mapa sku -> estoque atual, lido do catalogo (fonte usada pelo storefront). */
function svo_stock_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $catalog = svcr_products();
    if ($catalog !== []) {
            foreach ($catalog as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $sku = trim((string)($product['sku'] ?? ''));
                if ($sku !== '') {
                    $map[$sku] = (int)($product['stock'] ?? 0);
                }
            }
    }
    return $map;
}

function svo_load_runtime_secrets(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = svo_root() . '/config/runtime-secrets.php';
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

function svo_autodev_available(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $path = svo_root() . '/autodev/core/event_collector.php';
    if (!is_file($path) || !is_readable($path)) {
        $loaded = false;
        return false;
    }
    require_once $path;
    $loaded = function_exists('autodev_track');
    return $loaded;
}

function svo_order_dir(): string
{
    $preferred = svo_root() . '/storage/orders';
    if ((is_dir($preferred) || @mkdir($preferred, 0755, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-orders';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) {
        return $fallback;
    }

    return '';
}

function svo_append_legacy_order_log(array $order): void
{
    $logDir = svo_root() . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $entry = [
        'id' => $order['order_number'] ?? '',
        'timestamp' => $order['created_at'] ?? date('c'),
        'cliente' => [
            'nome' => $order['customer']['name'] ?? '',
            'email' => $order['customer']['email'] ?? '',
            'telefone' => $order['customer']['phone'] ?? '',
            'endereco' => $order['customer']['address'] ?? '',
            'numero' => '',
            'complemento' => '',
            'cidade' => '',
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
        $logDir . '/pedidos.jsonl',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function svo_payment_method(string $value): string
{
    $normalized = strtolower(trim($value));
    $allowed = ['pix', 'boleto', 'whatsapp', 'transferencia'];
    return in_array($normalized, $allowed, true) ? $normalized : 'pix';
}

function svo_payment_label(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto bancario',
        'whatsapp' => 'WhatsApp',
        'transferencia' => 'Transferencia bancaria',
        default => 'PIX',
    };
}

function svo_payment_instructions(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto sujeito a emissao manual apos confirmacao do frete.',
        'whatsapp' => 'Pagamento e frete serao alinhados pelo atendimento no WhatsApp.',
        'transferencia' => 'Dados bancarios serao enviados pela equipe apos confirmacao do frete.',
        default => 'Pagamento via PIX com confirmacao apos validacao do pedido.',
    };
}

function svo_tiny_credentials_configured(): bool
{
    return svo_tiny_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN') !== ''
        && svo_tiny_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID') !== ''
        && svo_tiny_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET') !== '';
}

/* ── Tiny ERP: push de pedido via API v3 ── */
function svo_tiny_env(string ...$keys): string {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        svo_load_runtime_secrets();
        $envFile = svo_root() . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
        $tf = svo_root() . '/storage/private/tokens.json';
        if (is_file($tf)) {
            $t = json_decode((string)file_get_contents($tf), true) ?: [];
            foreach ($t as $k => $v) {
                if (is_string($k) && is_string($v) && getenv($k) === false) {
                    putenv("$k=$v"); $_ENV[$k] = $v;
                }
            }
        }
    }
    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') return $v;
        if (isset($_ENV[$k]) && is_string($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    }
    return '';
}

function svo_tiny_get_token(): string {
    $TOKEN_URL    = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token';
    $refresh      = svo_tiny_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN');
    $clientId     = svo_tiny_env('OLIST_CLIENT_ID',     'TINY_CLIENT_ID');
    $clientSecret = svo_tiny_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET');
    if ($refresh === '' || $clientId === '' || $clientSecret === '') return '';

    $ch = curl_init($TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200) return '';
    $json = json_decode(is_string($body) ? $body : '', true);
    return is_array($json) ? (string)($json['access_token'] ?? '') : '';
}

function svo_push_order_tiny(array $order): ?string {
    $token = svo_tiny_get_token();
    if ($token === '') return null;

    $c = $order['customer'] ?? [];
    $cep = preg_replace('/\D/', '', (string)($c['cep'] ?? ''));
    $paymentMethod = svo_payment_label((string)($order['payment_method'] ?? 'pix'));
    $notes = trim((string)($order['notes'] ?? ''));
    $obs = trim("Forma de pagamento: {$paymentMethod}\n" . $notes);

    $payload = [
        'numeroPedido' => $order['order_number'],
        'situacao'     => ['id' => 1], // Aberto
        'cliente'      => [
            'nome'  => $c['name']  ?? '',
            'email' => $c['email'] ?? '',
            'fone'  => $c['phone'] ?? '',
            'enderecos' => [[
                'tipo'     => 'E',
                'cep'      => $cep,
                'endereco' => $c['address'] ?? '',
                'cidade'   => '',
                'uf'       => '',
            ]],
        ],
        'itens' => array_map(static fn(array $i) => [
            'codigo'      => $i['sku'],
            'descricao'   => $i['name'],
            'quantidade'  => $i['quantity'],
            'valor'       => $i['price'],
        ], $order['items'] ?? []),
        'obs' => $obs,
    ];

    $ch = curl_init('https://api.tiny.com.br/public-api/v3/pedidos');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ShopVivaliz/3.0',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status !== 200 && $status !== 201) {
        throw new RuntimeException("Tiny POST /pedidos HTTP $status: " . substr(is_string($body) ? $body : '', 0, 200));
    }
    $json = json_decode(is_string($body) ? $body : '', true);
    return (string)($json['id'] ?? $json['idPedido'] ?? '');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 200000) {
    svo_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svo_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$name = trim((string)($body['customer_name'] ?? ''));
$email = trim((string)($body['customer_email'] ?? ''));
$phone = trim((string)($body['customer_phone'] ?? ''));
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
$notes = trim((string)($body['notes'] ?? ''));
$paymentMethod = svo_payment_method((string)($body['payment_method'] ?? 'pix'));
$shippingTotal = max(0.0, (float)($body['shipping_total'] ?? 0));
$shippingLabel = trim((string)($body['shipping_label'] ?? ''));
$shippingService = trim((string)($body['shipping_service'] ?? ''));
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $cep));
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($address) > 300 || strlen($notes) > 1000) {
    svo_json(422, ['ok' => false, 'error' => 'field_too_long']);
}

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || strlen($cep) !== 8 || $address === '' || !$items) {
    svo_json(422, ['ok' => false, 'error' => 'missing_required_fields']);
}

if (count($items) > 100) {
    svo_json(422, ['ok' => false, 'error' => 'too_many_items']);
}

$cleanItems = [];
$itemsTotal = 0.0;
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $sku = trim((string)($item['sku'] ?? ''));
    $itemName = trim((string)($item['name'] ?? $sku));
    $quantity = max(1, min(99, (int)($item['quantity'] ?? 1)));
    $price = max(0.0, (float)($item['price'] ?? 0));
    if (strlen($sku) > 80 || strlen($itemName) > 220) continue;
    if ($sku === '' || $itemName === '') continue;
    $itemsTotal += $price * $quantity;
    $cleanItems[] = [
        'sku' => $sku,
        'name' => $itemName,
        'quantity' => $quantity,
        'price' => round($price, 2),
        'olist_product_id' => trim((string)($item['olist_product_id'] ?? '')),
    ];
}

if (!$cleanItems) {
    svo_json(422, ['ok' => false, 'error' => 'empty_items']);
}

// Bloqueia venda de item sem estoque suficiente (validacao no servidor).
$stockMap = svo_stock_map();
$stockIssues = [];
foreach ($cleanItems as $ci) {
    if (!array_key_exists($ci['sku'], $stockMap)) {
        continue;
    }
    $available = $stockMap[$ci['sku']];
    if ($available <= 0 || $ci['quantity'] > $available) {
        $stockIssues[] = [
            'sku' => $ci['sku'],
            'name' => $ci['name'],
            'requested' => $ci['quantity'],
            'available' => max(0, $available),
        ];
    }
}
if ($stockIssues) {
    svo_json(409, [
        'ok' => false,
        'error' => 'insufficient_stock',
        'message' => 'Um ou mais itens do carrinho nao tem estoque suficiente.',
        'items' => $stockIssues,
    ]);
}

$notesParts = [];
if ($notes !== '') {
    $notesParts[] = $notes;
}
if ($shippingLabel !== '' || $shippingTotal > 0) {
    $shippingNote = trim(implode(' | ', array_filter([
        $shippingLabel !== '' ? 'Frete: ' . $shippingLabel : '',
        $shippingTotal > 0 ? 'Valor do frete: R$ ' . number_format($shippingTotal, 2, ',', '.') : '',
    ])));
    if ($shippingNote !== '') {
        $notesParts[] = $shippingNote;
    }
}

$grandTotal = $itemsTotal + $shippingTotal;

$orderNumber = 'SV' . date('YmdHis') . random_int(100, 999);
$record = [
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'customer' => [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'cep' => $cep,
        'address' => $address,
    ],
    'items' => $cleanItems,
    'items_total' => round($itemsTotal, 2),
    'shipping_total' => round($shippingTotal, 2),
    'shipping_label' => $shippingLabel,
    'shipping_service' => $shippingService,
    'shipping_cep' => $shippingCep,
    'total' => round($grandTotal, 2),
    'payment_method' => $paymentMethod,
    'payment_label' => svo_payment_label($paymentMethod),
    'notes' => implode("\n", $notesParts),
    'created_at' => date('c'),
    'source' => 'site_checkout',
];

$dir = svo_order_dir();
if ($dir === '') {
    svo_json(500, ['ok' => false, 'error' => 'order_storage_unavailable']);
}
$path = $dir . '/' . $orderNumber . '.json';
if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    svo_json(500, ['ok' => false, 'error' => 'order_write_failed']);
}

if (svo_autodev_available()) {
    autodev_track('order_complete', [
        'order_number' => $orderNumber,
        'total' => round($grandTotal, 2),
        'payment_method' => $paymentMethod,
        'items_count' => count($cleanItems),
        'items' => array_map(static function (array $item): array {
            return [
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ];
        }, $cleanItems),
    ]);
}

// Enviar pedido ao Tiny ERP via API v3
$tinyOrderId  = null;
$tinyPushStatus = 'missing_credentials';
if (svo_tiny_credentials_configured()) {
    $tinyPushStatus = 'token_unavailable';
    try {
        $tinyOrderId = svo_push_order_tiny($record);
        if ($tinyOrderId) {
            $tinyPushStatus = 'ok';
            $record['tiny_order_id'] = $tinyOrderId;
        }
    } catch (Throwable $e) {
        $tinyPushStatus = $e->getMessage();
        error_log('[OrderCreate] Tiny push error: ' . $e->getMessage());
    }
}

$record['tiny_push'] = $tinyPushStatus;
file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
svo_append_legacy_order_log($record);

svo_json(200, [
    'ok' => true,
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'payment_method' => $paymentMethod,
    'payment_label' => $record['payment_label'],
    'message' => 'Pedido registrado para confirmacao manual de frete e pagamento.',
    'payment_instructions' => svo_payment_instructions($paymentMethod),
    'storage' => str_contains($dir, 'shopvivaliz-orders') ? 'fallback_temp' : 'storage_orders',
    'tiny_order_id' => $tinyOrderId,
    'tiny_push' => $tinyPushStatus,
    'subtotal' => round($itemsTotal, 2),
    'shipping_total' => round($shippingTotal, 2),
    'shipping_label' => $shippingLabel,
    'total' => round($grandTotal, 2),
]);
