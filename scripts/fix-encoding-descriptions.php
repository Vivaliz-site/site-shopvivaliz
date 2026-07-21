<?php
/**
 * Corrigir descrições com encoding UTF-8 duplo
 */
declare(strict_types=1);

$files = [
    __DIR__ . '/../api/catalog/fallback-products.json',
    __DIR__ . '/../storage/products-cache-ativos.json',
];

function fix_double_utf8($text) {
    if (!is_string($text)) {
        return $text;
    }

    // Verificar se contém sequências de duplo encoding
    if (strpos($text, 'ÃÂ') === false && strpos($text, 'Ã¢') === false) {
        return $text;
    }

    // Decodificar duplo UTF-8
    // Quando UTF-8 é decodificado como Latin-1 e depois re-codificado como UTF-8,
    // cada caractere fica duplicado como ÃÂ, Ã¢, etc.
    $text = iconv('CP1252', 'UTF-8//IGNORE', $text);

    // Tentar outra abordagem: converter de volta
    while (preg_match('/[ÃÂÃâÃ©Ã¡Ã³Ã¢]/u', $text)) {
        $text = utf8_decode($text);
    }

    return $text;
}

$processed = 0;
$fixed_count = 0;

foreach ($files as $filepath) {
    if (!is_file($filepath)) {
        echo "⚠️  Arquivo não encontrado: {$filepath}\n";
        continue;
    }

    echo "📝 Processando: {$filepath}\n";

    $content = file_get_contents($filepath);
    $data = json_decode($content, true);

    if (!is_array($data)) {
        echo "❌ JSON inválido\n";
        continue;
    }

    $items = &$data['itens'] ?? $data['items'] ?? $data['produtos'] ?? $data['products'] ?? $data;
    if (!is_array($items)) {
        echo "❌ Estrutura JSON não reconhecida\n";
        continue;
    }

    foreach ($items as &$item) {
        if (!is_array($item)) continue;
        $processed++;

        $desc_fields = ['descricao', 'description', 'descricaoComplementar', 'descricao_complementar'];

        foreach ($desc_fields as $field) {
            if (isset($item[$field]) && is_string($item[$field])) {
                $original = $item[$field];

                // Verificar se precisa de correção
                if (preg_match('/[ÃÂÃâ]/u', $original)) {
                    // Decodificar
                    $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $original);

                    // Se iconv não funcionou, tentar outra estratégia
                    if ($fixed === false || $fixed === '') {
                        $fixed = preg_replace_callback('/ÃÂ([ÃÂÃâ])/u', function($m) {
                            $map = [
                                'ÃÂÃ' => 'é',
                                'ÃÂÃ©' => 'é',
                                'ÃÂÃ¡' => 'á',
                                'ÃÂÃ³' => 'ó',
                                'ÃÂ' => 'Ã',
                            ];
                            return $map[$m[0]] ?? $m[0];
                        }, $original);
                    }

                    if ($fixed !== $original && $fixed !== '') {
                        $item[$field] = $fixed;
                        $fixed_count++;
                        $sku = $item['sku'] ?? $item['id'] ?? '?';
                        echo "  ✓ {$sku}: {$field} corrigida\n";
                    }
                }
            }
        }
    }

    // Salvar corrigido
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (file_put_contents($filepath, $json)) {
        echo "✅ Salvo!\n";
    } else {
        echo "❌ Erro ao salvar\n";
    }
}

echo "\n════════════════════════════════════════\n";
echo "📊 RESUMO\n";
echo "════════════════════════════════════════\n";
echo "Itens processados: {$processed}\n";
echo "Descrições corrigidas: {$fixed_count}\n";
echo "Status: " . ($fixed_count > 0 ? "✅ Sucesso" : "ℹ️  Nenhuma correção necessária") . "\n";
?>
