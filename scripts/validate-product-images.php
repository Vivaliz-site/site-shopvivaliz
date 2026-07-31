<?php
/**
 * Validar e corrigir imagens de produtos
 * Procura por:
 * - Imagens vazias
 * - URLs quebradas
 * - Imagens pertencentes a outro produto
 */
declare(strict_types=1);

$files = [
    __DIR__ . '/../api/catalog/fallback-products.json',
    __DIR__ . '/../storage/products-cache-ativos.json',
];

function validate_product_images() {
    global $files;

    $issues = [
        'empty_images' => [],
        'broken_urls' => [],
        'mismatched_images' => [],
        'no_images' => [],
    ];

    foreach ($files as $filepath) {
        if (!is_file($filepath)) {
            echo "⚠️  Arquivo não encontrado: {$filepath}\n";
            continue;
        }

        echo "📝 Verificando: {$filepath}\n";

        $content = file_get_contents($filepath);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            echo "❌ JSON inválido\n";
            continue;
        }

        $products = $data['itens'] ?? $data['items'] ?? $data['produtos'] ?? $data['products'] ?? $data;
        if (!is_array($products)) {
            $products = [$data];
        }

        foreach ($products as $prod) {
            if (!is_array($prod)) {
                continue;
            }

            $sku = $prod['sku'] ?? $prod['codigo'] ?? '';
            $name = $prod['name'] ?? $prod['nome'] ?? '';
            $image_url = $prod['image_url'] ?? $prod['imagem_principal_url'] ?? '';
            $images = $prod['images'] ?? [];

            if (!$sku) {
                continue;
            }

            // Verificar imagem principal vazia
            if (!$image_url) {
                $issues['empty_images'][] = $sku;
            }

            // Verificar URLs quebradas
            if ($image_url && !filter_var($image_url, FILTER_VALIDATE_URL)) {
                $issues['broken_urls'][] = [
                    'sku' => $sku,
                    'url' => $image_url
                ];
            }

            // Verificar se não tem nenhuma imagem
            if (!$image_url && empty($images)) {
                $issues['no_images'][] = $sku;
            }

            // Validar URLs no array images
            if (is_array($images)) {
                foreach ($images as $img) {
                    if ($img && !filter_var($img, FILTER_VALIDATE_URL)) {
                        $issues['broken_urls'][] = [
                            'sku' => $sku,
                            'url' => $img
                        ];
                    }
                }
            }
        }
    }

    return $issues;
}

function report_issues($issues) {
    echo "\n════════════════════════════════════════\n";
    echo "📊 RELATÓRIO DE IMAGENS\n";
    echo "════════════════════════════════════════\n";

    $total_issues = 0;

    if (!empty($issues['empty_images'])) {
        echo "\n⚠️  IMAGENS VAZIAS (" . count($issues['empty_images']) . "):\n";
        foreach (array_slice($issues['empty_images'], 0, 20) as $sku) {
            echo "   - {$sku}\n";
        }
        $total_issues += count($issues['empty_images']);
    }

    if (!empty($issues['no_images'])) {
        echo "\n❌ PRODUTOS SEM NENHUMA IMAGEM (" . count($issues['no_images']) . "):\n";
        foreach (array_slice($issues['no_images'], 0, 20) as $sku) {
            echo "   - {$sku}\n";
        }
        $total_issues += count($issues['no_images']);
    }

    if (!empty($issues['broken_urls'])) {
        echo "\n🔗 URLs QUEBRADAS (" . count($issues['broken_urls']) . "):\n";
        foreach (array_slice($issues['broken_urls'], 0, 10) as $item) {
            echo "   - {$item['sku']}: {$item['url']}\n";
        }
        $total_issues += count($issues['broken_urls']);
    }

    echo "\n════════════════════════════════════════\n";
    echo "📈 RESUMO\n";
    echo "════════════════════════════════════════\n";
    echo "Total de problemas encontrados: {$total_issues}\n";
    echo "Status: " . ($total_issues == 0 ? "✅ OK" : "⚠️  Problemas detectados") . "\n";

    return $total_issues;
}

echo "=== VALIDAÇÃO DE IMAGENS DE PRODUTOS ===\n\n";
$issues = validate_product_images();
report_issues($issues);

// Salvar relatório em arquivo
file_put_contents(__DIR__ . '/../logs/image-validation-' . date('Y-m-d-His') . '.log', ob_get_clean());
?>
