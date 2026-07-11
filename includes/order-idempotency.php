<?php
declare(strict_types=1);

function svoi_key(array $body, array $items): string {
    $provided = trim((string)($body['idempotency_key'] ?? ''));
    if ($provided !== '') return substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $provided) ?: '', 0, 120);
    $basis = [
        'email' => strtolower(trim((string)($body['customer_email'] ?? ''))),
        'cep' => preg_replace('/\D+/', '', (string)($body['cep'] ?? '')),
        'items' => array_map(static fn(array $item): array => [
            'sku' => (string)($item['sku'] ?? ''),
            'quantity' => (int)($item['quantity'] ?? 0),
            'price' => round((float)($item['price'] ?? 0), 2),
        ], $items),
        'shipping_quote_id' => (string)($body['shipping_quote_id'] ?? ''),
    ];
    return hash('sha256', json_encode($basis, JSON_UNESCAPED_SLASHES));
}

function svoi_dir(): string {
    $dir = dirname(__DIR__) . '/storage/order-idempotency';
    if ((is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir)) return $dir;
    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-order-idempotency';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) return $fallback;
    return '';
}

function svoi_claim(string $key, int $ttl = 900): bool {
    if ($key === '') return false;
    $dir = svoi_dir();
    if ($dir === '') return false;
    $path = $dir . '/' . hash('sha256', $key) . '.lock';
    if (is_file($path) && (time() - (int)filemtime($path)) < $ttl) return false;
    return file_put_contents($path, (string)time(), LOCK_EX) !== false;
}
