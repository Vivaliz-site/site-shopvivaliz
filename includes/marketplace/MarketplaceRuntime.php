<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/catalog-publication-schema.php';

final class SvMarketplaceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $requestId = '',
        public readonly array $response = []
    ) {
        parent::__construct($message);
    }
}

function sv_market_env(string ...$keys): string
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

function sv_market_json(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function sv_market_decode_json(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sv_market_safe_excerpt(string $raw, int $limit = 1500): string
{
    $raw = preg_replace('/(?:access_token|refresh_token|client_secret|authorization)\s*[=:]\s*[^\s,"}]+/i', '$1=[redacted]', $raw) ?? $raw;
    return function_exists('mb_substr') ? mb_substr($raw, 0, $limit) : substr($raw, 0, $limit);
}

function sv_market_assert_no_commerce_fields(array $payload, string $context): void
{
    $forbidden = [
        'price', 'prices', 'preco', 'precos', 'sale_price', 'list_price', 'cost_price',
        'inventory', 'inventories', 'stock', 'estoque', 'quantity', 'available_quantity',
        'fulfillment_availability', 'purchasable_offer', 'offers', 'offer', 'warehouse_inventory',
    ];
    $walk = static function (mixed $value, string $path = '') use (&$walk, $forbidden, $context): void {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            $name = strtolower((string)$key);
            $next = $path === '' ? $name : $path . '.' . $name;
            if (in_array($name, $forbidden, true)) {
                throw new LogicException("Campo comercial proibido em {$context}: {$next}");
            }
            $walk($child, $next);
        }
    };
    $walk($payload);
}

function sv_market_http_json(string $method, string $url, array $headers = [], ?array $jsonBody = null, int $timeout = 40): array
{
    $ch = curl_init($url);
    if ($ch === false) throw new SvMarketplaceException('Falha ao inicializar cURL.');
    $responseHeaders = [];
    $headerLines = [];
    foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
    if ($jsonBody !== null && !isset($headers['Content-Type'])) $headerLines[] = 'Content-Type: application/json';
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
    ];
    if ($jsonBody !== null) $options[CURLOPT_POSTFIELDS] = sv_market_json($jsonBody);
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw)) throw new SvMarketplaceException('Falha de transporte: ' . ($error !== '' ? $error : 'resposta vazia'), $status);
    $json = sv_market_decode_json($raw);
    $requestId = (string)($responseHeaders['x-request-id'] ?? $responseHeaders['x-amzn-requestid'] ?? $responseHeaders['x-amz-request-id'] ?? $json['request_id'] ?? $json['requestId'] ?? '');
    if ($status < 200 || $status >= 300) {
        $message = (string)($json['message'] ?? $json['error_description'] ?? $json['error'] ?? sv_market_safe_excerpt($raw));
        throw new SvMarketplaceException($message !== '' ? $message : "HTTP {$status}", $status, $requestId, $json);
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'json' => $json, 'raw' => $raw, 'request_id' => $requestId];
}

function sv_market_http_multipart(string $url, array $headers, array $fields, int $timeout = 60): array
{
    $ch = curl_init($url);
    if ($ch === false) throw new SvMarketplaceException('Falha ao inicializar upload multipart.');
    $headerLines = [];
    foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw)) throw new SvMarketplaceException('Falha no upload: ' . $error, $status);
    $json = sv_market_decode_json($raw);
    $requestId = (string)($json['request_id'] ?? $json['requestId'] ?? '');
    if ($status < 200 || $status >= 300) {
        throw new SvMarketplaceException((string)($json['message'] ?? sv_market_safe_excerpt($raw)), $status, $requestId, $json);
    }
    return ['status' => $status, 'json' => $json, 'raw' => $raw, 'request_id' => $requestId];
}

function sv_market_absolute_url(string $path): string
{
    if (preg_match('#^https?://#i', $path) === 1) return $path;
    $base = rtrim(sv_market_env('APP_URL'), '/');
    if ($base === '') $base = 'https://shopvivaliz.com.br';
    return $base . '/' . ltrim($path, '/');
}

function sv_market_product(PDO $db, int $productId): array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($product)) throw new RuntimeException("Produto #{$productId} não encontrado.");
    return $product;
}

function sv_market_mapping(PDO $db, int $productId, string $channel): ?array
{
    svcp_ensure_schema($db);
    $stmt = $db->prepare('SELECT * FROM product_channel_mappings WHERE product_id = ? AND channel = ? LIMIT 1');
    $stmt->execute([$productId, $channel]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function sv_market_save_mapping(PDO $db, int $productId, string $channel, string $externalId, string $sku, array $metadata = []): void
{
    svcp_ensure_schema($db);
    $stmt = $db->prepare(
        'INSERT INTO product_channel_mappings (product_id, channel, external_id, seller_sku, metadata_json, last_verified_at) '
        . 'VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), '
        . 'seller_sku = VALUES(seller_sku), metadata_json = VALUES(metadata_json), last_verified_at = NOW()'
    );
    $stmt->execute([$productId, $channel, $externalId, $sku, sv_market_json($metadata)]);
}

function sv_market_save_channel_content(PDO $db, int $productId, string $channel, array $content, bool $published): void
{
    svcp_ensure_schema($db);
    $stmt = $db->prepare(
        'INSERT INTO product_channel_content (product_id, channel, title, description, bullet_points_json, seo_keywords_json, marketing_hooks_json, meta_title, meta_description, attributes_json, images_json, published_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), '
        . 'description = VALUES(description), bullet_points_json = VALUES(bullet_points_json), seo_keywords_json = VALUES(seo_keywords_json), '
        . 'marketing_hooks_json = VALUES(marketing_hooks_json), meta_title = VALUES(meta_title), meta_description = VALUES(meta_description), '
        . 'attributes_json = VALUES(attributes_json), images_json = COALESCE(VALUES(images_json), images_json), published_at = VALUES(published_at)'
    );
    $stmt->execute([
        $productId, $channel, (string)($content['title'] ?? ''), (string)($content['description'] ?? ''),
        sv_market_json($content['bullet_points'] ?? []), sv_market_json($content['seo_keywords'] ?? []),
        sv_market_json($content['marketing_hooks'] ?? []), (string)($content['meta_title'] ?? ''),
        (string)($content['meta_description'] ?? ''), isset($content['attributes']) ? sv_market_json($content['attributes']) : null,
        isset($content['images']) ? sv_market_json($content['images']) : null, $published ? date('Y-m-d H:i:s') : null,
    ]);
}

function sv_market_write_publication(PDO $db, array $data): int
{
    svcp_ensure_schema($db);
    $stmt = $db->prepare(
        'INSERT INTO catalog_publications (publication_type, staging_id, product_id, channel, status, operation, external_id, http_status, request_id, fields_json, response_json, error_message, submitted_at, verified_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (string)($data['publication_type'] ?? 'text'), (int)($data['staging_id'] ?? 0),
        (int)($data['product_id'] ?? 0), (string)($data['channel'] ?? ''),
        (string)($data['status'] ?? 'failed'), (string)($data['operation'] ?? ''),
        (string)($data['external_id'] ?? ''), (int)($data['http_status'] ?? 0),
        (string)($data['request_id'] ?? ''), isset($data['fields']) ? sv_market_json($data['fields']) : null,
        isset($data['response']) ? sv_market_json($data['response']) : null,
        isset($data['error_message']) ? (string)$data['error_message'] : null,
        !empty($data['submitted']) ? date('Y-m-d H:i:s') : null,
        !empty($data['verified']) ? date('Y-m-d H:i:s') : null,
    ]);
    return (int)$db->lastInsertId();
}
