<?php
/**
 * API de Catálogo - Busca DIRETO do ERP OLIST (Tiny)
 *
 * Substitui a busca no banco de dados local
 * FONTE DE VERDADE: ERP OLIST apenas
 */

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function get_erp_token(): ?string
{
    // Carregar token do ERP
    $token_file = dirname(__DIR__, 2) . '/storage/private/tokens.json';
    if (is_file($token_file) && is_readable($token_file)) {
        $tokens = json_decode(file_get_contents($token_file), true);
        if (is_array($tokens)) {
            return $tokens['OLIST_ACCESS_TOKEN'] ?? $tokens['TINY_ACCESS_TOKEN'] ?? null;
        }
    }

    // Fallback: .env
    $env_file = dirname(__DIR__, 2) . '/.env';
    if (is_file($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=') || str_starts_with($line, 'TINY_ACCESS_TOKEN=')) {
                $parts = explode('=', $line, 2);
                return trim(trim($parts[1] ?? ''), "\"'");
            }
        }
    }

    return null;
}

function fetch_erp_products(int $page = 1, int $limit = 100): array
{
    $token = get_erp_token();
    if (!$token) {
        return ['error' => 'ERP token not configured'];
    }

    // URL da Tiny API
    $url = "https://api.tiny.com.br/api/v2/produtos?pagina={$page}&limite={$limit}";

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if (!$response) {
        return ['error' => 'ERP API unreachable'];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['error' => 'ERP API invalid response'];
    }

    return $data;
}

function normalize_erp_product(array $item): array
{
    // Converter dados da Tiny para nosso formato
    return [
        'id' => (string)($item['id'] ?? ''),
        'sku' => trim((string)($item['codigo'] ?? '')),
        'olist_product_id' => (string)($item['id'] ?? ''),
        'name' => trim((string)($item['nome'] ?? 'Produto')),
        'description' => trim((string)($item['descricao_complementar'] ?? '')),
        'price' => (float)($item['preco'] ?? 0),
        'stock' => (int)($item['estoque'] ?? 0),
        'image_url' => trim((string)($item['imagem_principal_url'] ?? '')),
        'images_count' => (int)($item['imagens_count'] ?? 1),
        'status' => 'active',
    ];
}

function get_all_erp_products(): array
{
    $all_products = [];
    $page = 1;
    $max_pages = 50; // Limite de segurança

    while ($page <= $max_pages) {
        $response = fetch_erp_products($page, 100);

        if (isset($response['error'])) {
            break;
        }

        $items = $response['data']['itens'] ?? [];
        if (!is_array($items) || empty($items)) {
            break;
        }

        foreach ($items as $item) {
            $all_products[] = normalize_erp_product($item);
        }

        if (count($items) < 100) {
            break; // Última página
        }

        $page++;
        usleep(500000); // 0.5s entre requisições
    }

    return $all_products;
}

// ============= MAIN =============

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$q = trim((string)($_GET['q'] ?? ''));

// Buscar produtos do ERP
$products = get_all_erp_products();

// Filtrar por busca se necessário
if ($q !== '') {
    $products = array_filter($products, function($p) use ($q) {
        $searchText = strtoupper($p['sku'] . ' ' . $p['name']);
        return strpos($searchText, strtoupper($q)) !== false;
    });
}

// Limitar resultado
$products = array_slice(array_values($products), 0, $limit);

// Extrair categorias (fallback)
$categories = [];

// Retornar resposta
http_response_code(200);
echo json_encode([
    'ok' => true,
    'source' => 'erp_olist',
    'count' => count($products),
    'products' => array_values($products),
    'categories' => $categories,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
