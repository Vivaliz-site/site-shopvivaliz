<?php
chdir('/home/ubuntu/site-shopvivaliz');

$jsonPath = 'api/catalog/fallback-products.json';
$content = file_get_contents($jsonPath);
if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
    $content = substr($content, 3);
}

$catalog = json_decode($content, true);
if (!is_array($catalog)) {
    echo "❌ JSON inválido\n";
    exit(1);
}

// Contar ANTES
$activeBefore = 0;
$testKey = null;
foreach ($catalog as $key => $product) {
    if (($product['status'] ?? 'active') === 'active') {
        $activeBefore++;
        if ($testKey === null && !empty($product['sku'])) {
            $testKey = $key;
        }
    }
}

echo "❶ Contagem ANTES: $activeBefore produtos\n";
echo "❷ Testando SKU: " . (isset($catalog[$testKey]['sku']) ? $catalog[$testKey]['sku'] : 'N/A') . "\n";

// Marcar como inativo
if ($testKey !== null) {
    $catalog[$testKey]['status'] = 'inactive';
    $settings = new stdClass();
    file_put_contents($jsonPath, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

sleep(4);

// Contar DEPOIS via curl
$ch = curl_init('http://localhost/api/catalog/products.php?limit=200');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

$apiData = json_decode($response, true);
$countAfter = $apiData['count'] ?? 0;

echo "❸ Contagem DEPOIS: $countAfter produtos\n\n";

if ($countAfter < $activeBefore) {
    echo "✅ FILTRO FUNCIONANDO!\n";
    echo "   Produto foi removido da API\n";
} else {
    echo "❌ Filtro NÃO funcionou\n";
    echo "   Esperado: < $activeBefore, Obtido: $countAfter\n";
}

// Desfazer
system('git checkout api/catalog/fallback-products.json 2>&1');
echo "✅ Teste desfeito\n";
?>
