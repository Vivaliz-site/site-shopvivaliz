<?php
/**
 * Corrigir descrições quebradas em JSON
 * Remove tags HTML, HTML entities, avisos quebrados
 */
declare(strict_types=1);

$files = [
    __DIR__ . '/../api/catalog/fallback-products.json',
    __DIR__ . '/../storage/products-cache-ativos.json',
];

function clean_description($desc) {
    if (!is_string($desc) || trim($desc) === '') {
        return '';
    }

    // Remover tags HTML
    $desc = preg_replace('/<[^>]+>/', '', $desc);

    // Remover avisos quebrados
    $desc = preg_replace('/FOTOS\s+MERAMENTE\s+ILUSTRATIVAS/i', '', $desc);
    $desc = preg_replace('/^\s*-\s*|\s*-\s*$/m', '', $desc);

    // Decodificar HTML entities
    $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Limpar espaços em branco extras
    $desc = preg_replace('/\s+/', ' ', trim($desc));

    return trim($desc);
}

$fixed_count = 0;
$files_processed = 0;

foreach ($files as $filepath) {
    if (!is_file($filepath)) {
        echo "⚠️  Arquivo não encontrado: {$filepath}\n";
        continue;
    }

    echo "📝 Processando: {$filepath}\n";

    $content = file_get_contents($filepath);
    if (!$content) {
        echo "❌ Erro ao ler arquivo\n";
        continue;
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        echo "❌ JSON inválido\n";
        continue;
    }

    // Processar produtos
    $items = &$data['itens'];
    if (!isset($items)) {
        $items = &$data['items'];
    }
    if (!isset($items)) {
        $items = &$data['produtos'];
    }
    if (!isset($items)) {
        $items = &$data['products'];
    }
    if (!is_array($items)) {
        $items = &$data;
    }

    $item_count = 0;
    foreach ($items as &$item) {
        if (!is_array($item)) {
            continue;
        }

        // Campos de descrição
        $desc_fields = ['descricao', 'description', 'nome', 'name', 'descricaoComplementar', 'descricao_complementar'];

        foreach ($desc_fields as $field) {
            if (isset($item[$field]) && is_string($item[$field])) {
                $original = $item[$field];
                $cleaned = clean_description($original);

                if ($original !== $cleaned && $cleaned !== '') {
                    $item[$field] = $cleaned;
                    $fixed_count++;
                    $item_count++;

                    // Log de mudança
                    $sku = $item['sku'] ?? $item['id'] ?? '?';
                    echo "  ✓ {$sku}: {$field} corrigida\n";
                }
            }
        }
    }

    // Salvar arquivo corrigido
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($filepath, $json)) {
        echo "✅ Salvo! {$item_count} descrições corrigidas\n";
        $files_processed++;
    } else {
        echo "❌ Erro ao salvar arquivo\n";
    }

    echo "\n";
}

echo "════════════════════════════════════════\n";
echo "📊 RESUMO\n";
echo "════════════════════════════════════════\n";
echo "Arquivos processados: {$files_processed}\n";
echo "Total de descrições corrigidas: {$fixed_count}\n";
echo "Status: " . ($fixed_count > 0 ? "✅ Sucesso" : "ℹ️  Nenhuma correção necessária") . "\n";
?>
