<?php
/**
 * Busca estoque via API V2 Integrador (Tiny)
 * Esta API não expira, ao contrário da V3 OAuth
 */

declare(strict_types=1);

function get_integrador_credentials(): array
{
    $root = dirname(__DIR__);
    $env_file = $root . '/.env';
    $creds = [];

    if (is_file($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'OLIST_INTEGRADOR_ID=')) {
                $creds['id'] = explode('=', $line, 2)[1] ?? '';
            } elseif (str_starts_with($line, 'OLIST_INTEGRADOR_TOKEN=')) {
                $creds['token'] = explode('=', $line, 2)[1] ?? '';
            }
        }
    }

    return $creds;
}

/**
 * Busca estoque de UM produto via API V2
 * @param string $id_produto ID do produto na Tiny
 * @param string $token Token da API V2
 */
function get_product_estoque_v2(string $id_produto, string $token): ?int
{
    $url = "https://api.tiny.com.br/3/produtos.json?id={$id_produto}&formato=json";

    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'header' => "Content-Type: application/json\r\n",
            'timeout' => 15,
        ]
    ]);

    $response = @file_get_contents($url . "&token={$token}", false, $context);

    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);

    // API V2 retorna em 'produto' > 'estoque'
    if (isset($data['retorno']['produtos'][0]['produto']['estoque'])) {
        return (int)$data['retorno']['produtos'][0]['produto']['estoque'];
    }

    return null;
}

/**
 * Enriquece cache com dados de estoque via V2
 */
function enrich_cache_with_estoque_v2(): void
{
    $root = dirname(__DIR__);
    $cache_file = $root . '/storage/products-cache-ativos.json';
    $creds = get_integrador_credentials();

    if (!$creds['token'] ?? false) {
        error_log("[fetch-estoque-v2] Token integrador não configurado");
        return;
    }

    if (!is_file($cache_file)) {
        error_log("[fetch-estoque-v2] Cache não encontrado");
        return;
    }

    $cache = json_decode(file_get_contents($cache_file), true);

    if (!isset($cache['itens'])) {
        return;
    }

    $updated_count = 0;

    // Enriquecer cada produto com estoque
    foreach ($cache['itens'] as &$item) {
        if (isset($item['id']) && !isset($item['estoque_disponivel'])) {
            $estoque = get_product_estoque_v2((string)$item['id'], $creds['token']);

            if ($estoque !== null) {
                $item['estoque_disponivel'] = $estoque;
                $updated_count++;
            }

            // Rate limit
            usleep(200000);  // 200ms entre requests
        }
    }

    // Salvar cache enriquecido
    file_put_contents($cache_file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    error_log("[fetch-estoque-v2] Enriquecidos $updated_count produtos com estoque via V2");
}

// Executar
enrich_cache_with_estoque_v2();
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Cache enriquecido com dados de estoque via API V2'
]);
?>
