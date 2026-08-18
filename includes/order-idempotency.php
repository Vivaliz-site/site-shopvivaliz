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
    // Rodada 2 (2026-08-18): dirname(__DIR__) resolve para dentro do release
    // imutavel (releases/<timestamp>-<sha>/), trocado por symlink a cada
    // deploy. Zerar idempotencia de pedido numa janela de deploy pode
    // permitir pedido duplicado -- mais serio que o mesmo problema no rate
    // limit, porque aqui e dinheiro. SHOPVIVALIZ_RUNTIME_DIR aponta para um
    // caminho compartilhado fora do release; sem a variavel, comportamento
    // identico ao anterior. Ver E2 no relatorio da Rodada 2.
    $runtimeBase = rtrim((string)(getenv('SHOPVIVALIZ_RUNTIME_DIR') ?: ''), '/\\');
    $dir = ($runtimeBase !== '' ? $runtimeBase : dirname(__DIR__)) . '/storage/order-idempotency';
    if ((is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir)) return $dir;
    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-order-idempotency';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) return $fallback;
    return '';
}

function svoi_path(string $key): string {
    $dir = svoi_dir();
    return $dir === '' ? '' : $dir . '/' . hash('sha256', $key) . '.lock';
}

function svoi_cleanup(int $ttl = 900): void {
    $dir = svoi_dir();
    if ($dir === '') return;
    foreach (glob($dir . '/*.lock') ?: [] as $file) {
        if (is_file($file) && (time() - (int)filemtime($file)) >= $ttl) @unlink($file);
    }
}

function svoi_claim(string $key, int $ttl = 900): bool {
    if ($key === '') return false;
    svoi_cleanup($ttl);
    $path = svoi_path($key);
    if ($path === '') return false;
    $handle = @fopen($path, 'x');
    if ($handle === false) return false;
    fwrite($handle, json_encode(['created_at'=>time(),'key_hash'=>hash('sha256',$key)]));
    fclose($handle);
    return true;
}

function svoi_release(string $key): void {
    $path = svoi_path($key);
    if ($path !== '' && is_file($path)) @unlink($path);
}
