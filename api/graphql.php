<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function gql_root(): string
{
    return dirname(__DIR__);
}

function gql_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function gql_env_load(): void
{
    $path = gql_root() . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function gql_header(string $name): string
{
    $value = $_SERVER[$name] ?? $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($name))] ?? '';
    return trim((string)$value);
}

function gql_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $raw = gql_header($key);
        if ($raw === '') {
            continue;
        }
        if ($key === 'HTTP_X_FORWARDED_FOR' && str_contains($raw, ',')) {
            $parts = array_map('trim', explode(',', $raw));
            $raw = $parts[0] ?? '';
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return $raw;
        }
    }
    return 'unknown';
}

function gql_rate_limit(): void
{
    $limit = (int)(getenv('GRAPHQL_RATE_LIMIT_PER_MINUTE') ?: 60);
    if ($limit <= 0) {
        $limit = 60;
    }

    $window = 60;
    $ip = gql_client_ip();
    $dir = gql_root() . '/storage/rate-limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/graphql-' . md5($ip) . '.json';
    $now = time();
    $state = ['window_start' => $now, 'count' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }

    if (($now - (int)$state['window_start']) >= $window) {
        $state = ['window_start' => $now, 'count' => 0];
    }

    $state['count'] = (int)$state['count'] + 1;
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    if ($state['count'] > $limit) {
        $retryAfter = max(1, $window - ($now - (int)$state['window_start']));
        header('Retry-After: ' . $retryAfter);
        gql_json(429, [
            'ok' => false,
            'errors' => [[
                'message' => 'Rate limit excedido para GraphQL.',
                'extensions' => [
                    'code' => 'RATE_LIMITED',
                    'retry_after' => $retryAfter,
                ],
            ]],
        ]);
    }
}

function gql_read_json(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function gql_catalog(): array
{
    $path = gql_root() . '/api/catalog/fallback-products.json';
    $items = gql_read_json($path);
    $normalized = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized[] = [
            'id' => (string)($row['id'] ?? $row['olist_product_id'] ?? $row['sku'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'olist_product_id' => (string)($row['olist_product_id'] ?? $row['olist_id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'price' => (float)($row['price'] ?? 0),
            'stock' => (int)($row['stock'] ?? 0),
            'image_url' => (string)($row['image_url'] ?? ''),
            'images_count' => (int)($row['images_count'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
        ];
    }
    return $normalized;
}

function gql_orders(): array
{
    $dir = gql_root() . '/storage/orders';
    if (!is_dir($dir)) {
        return [];
    }
    $orders = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $decoded = gql_read_json($file);
        if ($decoded) {
            $orders[] = $decoded;
        }
    }
    return $orders;
}

function gql_feedback(): array
{
    $dir = gql_root() . '/storage/support-feedback';
    if (!is_dir($dir)) {
        return [];
    }
    $feedback = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $name = basename($file);
        if ($name === 'latest-summary.json') {
            continue;
        }
        $decoded = gql_read_json($file);
        if ($decoded) {
            $feedback[] = $decoded;
        }
    }
    return $feedback;
}

function gql_parse_args(string $input): array
{
    $args = [];
    $input = trim($input);
    if ($input === '') {
        return $args;
    }
    $pattern = '/([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(\[[^\]]*\]|"([^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|true|false|null|-?\d+(?:\.\d+)?)/';
    if (!preg_match_all($pattern, $input, $matches, PREG_SET_ORDER)) {
        return $args;
    }
    foreach ($matches as $match) {
        $key = $match[1];
        $raw = trim($match[2]);
        if ($raw === 'true') {
            $args[$key] = true;
        } elseif ($raw === 'false') {
            $args[$key] = false;
        } elseif ($raw === 'null') {
            $args[$key] = null;
        } elseif ($raw !== '' && ($raw[0] === '"' || $raw[0] === '\'')) {
            $args[$key] = stripcslashes(substr($raw, 1, -1));
        } elseif ($raw !== '' && $raw[0] === '[') {
            $values = [];
            if (preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\']*)\'|([^,\s\]]+)/', $raw, $vm, PREG_SET_ORDER)) {
                foreach ($vm as $val) {
                    $values[] = stripcslashes($val[1] !== '' ? $val[1] : ($val[2] !== '' ? $val[2] : $val[3]));
                }
            }
            $args[$key] = $values;
        } elseif (str_contains($raw, '.')) {
            $args[$key] = (float)$raw;
        } else {
            $args[$key] = (int)$raw;
        }
    }
    return $args;
}

function gql_extract_query(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    if (preg_match('/\b(query|mutation)\b\s*(?:[A-Za-z_][A-Za-z0-9_]*)?\s*\{(.*)\}\s*$/s', $query, $m)) {
        $query = trim($m[2]);
    } elseif ($query[0] === '{' && str_ends_with($query, '}')) {
        $query = trim(substr($query, 1, -1));
    }

    $fields = [];
    if (!preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(([^{}]*)\))?\s*(?:\{([^{}]*)\})?/s', $query, $matches, PREG_SET_ORDER)) {
        return $fields;
    }

    foreach ($matches as $match) {
        $fields[] = [
            'name' => $match[1],
            'args' => gql_parse_args($match[2] ?? ''),
            'selection' => array_values(array_filter(array_map('trim', preg_split('/\s+/', trim((string)($match[3] ?? '')) ?: '')))),
        ];
    }
    return $fields;
}

function gql_select(array $item, array $fields): array
{
    if (!$fields) {
        return $item;
    }
    $selected = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $item)) {
            $selected[$field] = $item[$field];
        }
    }
    return $selected;
}

function gql_products(array $args, array $selection): array
{
    $items = gql_catalog();
    $limit = max(1, min(50, (int)($args['limit'] ?? 12)));
    $q = trim((string)($args['q'] ?? $args['query'] ?? ''));
    $category = trim((string)($args['category'] ?? ''));

    $filtered = [];
    foreach ($items as $item) {
        $haystack = strtolower($item['sku'] . ' ' . $item['name'] . ' ' . $item['description'] . ' ' . $item['category']);
        if ($q !== '' && strpos($haystack, strtolower($q)) === false) {
            continue;
        }
        if ($category !== '' && strcasecmp((string)$item['category'], $category) !== 0) {
            continue;
        }
        $filtered[] = gql_select($item, $selection);
        if (count($filtered) >= $limit) {
            break;
        }
    }
    return $filtered;
}

function gql_product(array $args, array $selection): ?array
{
    $lookup = trim((string)($args['id'] ?? $args['sku'] ?? $args['olist_product_id'] ?? ''));
    if ($lookup === '') {
        return null;
    }
    foreach (gql_catalog() as $item) {
        if ($lookup === $item['id'] || $lookup === $item['sku'] || $lookup === $item['olist_product_id']) {
            return gql_select($item, $selection);
        }
    }
    return null;
}

function gql_stats(): array
{
    $catalog = gql_catalog();
    $orders = gql_orders();
    $feedback = gql_feedback();

    $monthlyOrders = 0;
    $month = date('Y-m');
    foreach ($orders as $order) {
        $created = (string)($order['created_at'] ?? '');
        if ($created !== '' && str_starts_with($created, $month)) {
            $monthlyOrders++;
        }
    }

    return [
        'catalog_count' => count($catalog),
        'orders_count' => count($orders),
        'monthly_orders_count' => $monthlyOrders,
        'feedback_count' => count($feedback),
        'generated_at' => date('c'),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Allow: GET, POST, OPTIONS');
    exit;
}

gql_rate_limit();

$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $input = [
        'query' => (string)($_GET['query'] ?? ''),
        'variables' => isset($_GET['variables']) && is_string($_GET['variables']) ? json_decode($_GET['variables'], true) : [],
    ];
} else {
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 200000) {
        gql_json(413, ['ok' => false, 'errors' => [['message' => 'Payload muito grande.']]]);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        gql_json(400, ['ok' => false, 'errors' => [['message' => 'JSON invalido.']]]);
    }
    $input = $decoded;
}

$query = trim((string)($input['query'] ?? ''));
if ($query === '') {
    gql_json(400, [
        'ok' => false,
        'errors' => [[
            'message' => 'Campo query é obrigatório.',
            'extensions' => ['code' => 'BAD_USER_INPUT'],
        ]],
    ]);
}

$fields = gql_extract_query($query);
if (!$fields) {
    gql_json(400, [
        'ok' => false,
        'errors' => [[
            'message' => 'Não foi possível interpretar a query GraphQL.',
            'extensions' => ['code' => 'GRAPHQL_PARSE_FAILED'],
        ]],
    ]);
}

$data = [];
$errors = [];
foreach ($fields as $field) {
    switch ($field['name']) {
        case 'products':
            $data['products'] = gql_products($field['args'], $field['selection']);
            break;
        case 'product':
            $data['product'] = gql_product($field['args'], $field['selection']);
            if ($data['product'] === null) {
                $errors[] = [
                    'message' => 'Produto não encontrado.',
                    'path' => ['product'],
                    'extensions' => ['code' => 'NOT_FOUND'],
                ];
            }
            break;
        case 'stats':
            $data['stats'] = gql_stats();
            break;
        case 'health':
            $data['health'] = [
                'ok' => true,
                'generated_at' => date('c'),
                'service' => 'shopvivaliz-graphql',
            ];
            break;
        default:
            $errors[] = [
                'message' => 'Campo não suportado: ' . $field['name'],
                'path' => [$field['name']],
                'extensions' => ['code' => 'FIELD_NOT_SUPPORTED'],
            ];
            break;
    }
}

$response = [
    'ok' => true,
    'data' => $data,
    'meta' => [
        'service' => 'shopvivaliz-graphql',
        'generated_at' => date('c'),
        'rate_limit_per_minute' => (int)(getenv('GRAPHQL_RATE_LIMIT_PER_MINUTE') ?: 60),
    ],
];
if ($errors) {
    $response['errors'] = $errors;
}

gql_json(200, $response);
