<?php
/**
 * Corrigir imagens de produtos faltando/erradas
 * Sincroniza com Tiny/Olist via API
 */
declare(strict_types=1);

echo "=== CORRIGINDO IMAGENS DE PRODUTOS ===\n\n";

// Carregar token
$env_file = __DIR__ . '/../.env';
$token = '';

if (is_file($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=') || str_starts_with($line, 'TINY_ACCESS_TOKEN=')) {
            $parts = explode('=', $line, 2);
            $token = trim(trim($parts[1] ?? ''), "\"'");
            break;
        }
    }
}

if (!$token) {
    echo "❌ Token não encontrado em .env\n";
    exit(1);
}

echo "🔑 Token encontrado\n";

// SKUs que precisam de correção
$skus_to_fix = ['Parafuso5x16'];

foreach ($skus_to_fix as $sku) {
    echo "\n📦 Buscando imagem para: {$sku}\n";

    // Buscar do Tiny via API v3
    $url = "https://api.tiny.com.br/public-api/v3/produtos?filtro_nome={$sku}&limit=1";

    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        echo "❌ Falha ao buscar do Tiny\n";
        continue;
    }

    $data = json_decode($response, true);

    if (!isset($data['itens']) || empty($data['itens'])) {
        echo "⚠️  Produto não encontrado no Tiny\n";
        continue;
    }

    $product = $data['itens'][0];
    $image_url = $product['imagem_principal_url'] ?? $product['image_url'] ?? '';

    if (!$image_url) {
        echo "⚠️  Produto não tem imagem no Tiny\n";

        // Tentar usar a primeira imagem do array anexos
        if (isset($product['anexos']) && is_array($product['anexos']) && !empty($product['anexos'])) {
            $image_url = $product['anexos'][0]['url'] ?? '';
            echo "ℹ️  Usando anexo: {$image_url}\n";
        }
    } else {
        echo "✓ Imagem encontrada: {$image_url}\n";
    }

    if ($image_url) {
        // Atualizar nos arquivos JSON
        update_product_image_in_file(
            __DIR__ . '/../api/catalog/fallback-products.json',
            $sku,
            $image_url
        );

        update_product_image_in_file(
            __DIR__ . '/../storage/products-cache-ativos.json',
            $sku,
            $image_url
        );

        echo "✅ Imagem atualizada para {$sku}\n";
    }

    usleep(500000); // Rate limit
}

function update_product_image_in_file($filepath, $sku, $image_url) {
    if (!is_file($filepath)) {
        return;
    }

    $content = file_get_contents($filepath);
    $data = json_decode($content, true);

    if (!is_array($data)) {
        return;
    }

    $products = &$data['itens'];
    if (!isset($products)) {
        $products = &$data['items'];
    }
    if (!isset($products)) {
        $products = &$data['produtos'];
    }
    if (!isset($products)) {
        $products = &$data['products'];
    }

    if (!is_array($products)) {
        return;
    }

    foreach ($products as &$prod) {
        if (!is_array($prod)) {
            continue;
        }

        if (($prod['sku'] ?? '') === $sku) {
            $prod['image_url'] = $image_url;
            $prod['imagem_principal_url'] = $image_url;

            // Adicionar ao array images se não estiver lá
            if (!isset($prod['images'])) {
                $prod['images'] = [];
            }
            if (is_array($prod['images']) && !in_array($image_url, $prod['images'])) {
                array_unshift($prod['images'], $image_url);
            }

            break;
        }
    }

    file_put_contents($filepath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

echo "\n════════════════════════════════════════\n";
echo "✅ Correcção concluída\n";
?>
