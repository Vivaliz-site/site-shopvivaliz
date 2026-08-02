<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$baseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: 'https://shopvivaliz.com.br'), '/');
$url = $baseUrl . '/produto/chave-teste-140mm-100500v?review_integrity=' . rawurlencode((string)time());
$handle = curl_init($url);
curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    CURLOPT_USERAGENT => 'ShopVivaliz-Review-Integrity/1.0',
]);
$html = curl_exec($handle);
$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
$error = curl_errno($handle);
curl_close($handle);

$forbidden = [
    'Baseado em compras verificadas',
    'Carlos M. - Divinópolis/MG',
    'Fernanda S. - São Paulo/SP',
    '4.9/5 - Excelente',
    'Excelente produto! A entrega foi super rápida',
];
$present = [];
if (is_string($html)) {
    foreach ($forbidden as $phrase) {
        if (str_contains($html, $phrase)) $present[] = $phrase;
    }
}

$required = [
    'Chave Teste 140MM 100/500V',
    'COMPRAR AGORA',
];

$catalogUrl = $baseUrl . '/api/catalog/products.php?limit=20&busca=' . rawurlencode('chave teste 140mm');
$catalogHandle = curl_init($catalogUrl);
curl_setopt_array($catalogHandle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'ShopVivaliz-Review-Integrity/1.0',
]);
$catalogBody = curl_exec($catalogHandle);
$catalogStatus = (int)curl_getinfo($catalogHandle, CURLINFO_RESPONSE_CODE);
$catalogError = curl_errno($catalogHandle);
curl_close($catalogHandle);

$expectedPrice = null;
if ($catalogError === 0 && $catalogStatus === 200 && is_string($catalogBody)) {
    $catalog = json_decode($catalogBody, true);
    foreach (($catalog['products'] ?? []) as $product) {
        if (($product['sku'] ?? '') === 'SKU-343577002' && isset($product['price'])) {
            $expectedPrice = 'R$ ' . number_format((float)$product['price'], 2, ',', '.');
            break;
        }
    }
}
if ($expectedPrice !== null) {
    $required[] = $expectedPrice;
} else {
    $required[] = 'R$ ';
}
$missing = [];
if (is_string($html)) {
    foreach ($required as $phrase) {
        if (!str_contains($html, $phrase)) $missing[] = $phrase;
    }
} else {
    $missing = $required;
}

$result = [
    'ok' => $error === 0 && $status === 200 && $present === [] && $missing === [],
    'status' => $status,
    'transport_error' => $error !== 0,
    'forbidden_claims_present' => count($present),
    'required_product_contract_missing' => count($missing),
    'checked_url_path' => '/produto/chave-teste-140mm-100500v',
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
