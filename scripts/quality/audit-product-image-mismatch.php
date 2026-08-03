<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog-runtime.php';

/**
 * Audita imagens que podem nao pertencer ao produto.
 *
 * A regra e conservadora: nao remove imagens, apenas sinaliza risco para revisao.
 * Isso atende casos como galeria com imagem 3 ou 4 visualmente errada sem apagar
 * imagens validas automaticamente.
 */

function svqa_norm(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($ascii) && $ascii !== '') $text = strtolower($ascii);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function svqa_tokens(string $text): array
{
    $stop = array_flip([
        'a','o','os','as','de','da','do','das','dos','e','em','com','para','por','um','uma','un','kit','jogo','peca','pecas','cm','mm','ml','l','n','cor','cores','azul','preto','branco','prata','cinza','amarelo','cromado','inox'
    ]);
    $tokens = [];
    foreach (explode(' ', svqa_norm($text)) as $token) {
        if ($token === '' || strlen($token) < 3 || isset($stop[$token])) continue;
        if (preg_match('/^\d+$/', $token)) continue;
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

function svqa_url_text(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $path = is_string($path) ? $path : $url;
    return svqa_norm(basename($path));
}

function svqa_overlap_score(array $productTokens, string $urlText): float
{
    if ($productTokens === []) return 0.0;
    $hits = 0;
    foreach ($productTokens as $token) {
        if (str_contains($urlText, $token)) $hits++;
    }
    return $hits / max(1, count($productTokens));
}

function svqa_image_risk(array $product, string $url, int $position): array
{
    $name = (string)($product['name'] ?? '');
    $sku = (string)($product['sku'] ?? '');
    $category = (string)($product['category'] ?? '');
    $urlLower = strtolower($url);
    $urlText = svqa_url_text($url);
    $tokens = svqa_tokens($name . ' ' . $sku . ' ' . $category);
    $overlap = svqa_overlap_score($tokens, $urlText);

    $risk = 0;
    $reasons = [];

    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        $risk += 100;
        $reasons[] = 'url_invalida';
    }
    foreach (['placeholder', 'logo', 'sem-imagem', 'no-image', 'default'] as $bad) {
        if (str_contains($urlLower, $bad)) {
            $risk += 90;
            $reasons[] = 'asset_generico';
            break;
        }
    }
    if ($position >= 3) {
        $risk += 15;
        $reasons[] = 'posicao_tardia_para_revisao';
    }
    if ($position >= 6) {
        $risk += 10;
        $reasons[] = 'galeria_extensa';
    }
    if ($overlap <= 0.05 && !preg_match('/\b' . preg_quote(svqa_norm($sku), '/') . '\b/', $urlText)) {
        $risk += 35;
        $reasons[] = 'baixa_correspondencia_nome_url';
    }
    if (preg_match('/\b(amazon|ml|mercadolivre|marketplace|ambientada|beneficios|detalhes)\b/', $urlText)) {
        $risk += 12;
        $reasons[] = 'imagem_de_canal_ou_marketing';
    }

    $risk = min(100, $risk);
    $status = $risk >= 70 ? 'high' : ($risk >= 40 ? 'medium' : ($risk > 0 ? 'low' : 'ok'));

    return [
        'status' => $status,
        'risk' => $risk,
        'position' => $position,
        'overlap' => round($overlap, 4),
        'reasons' => array_values(array_unique($reasons)),
    ];
}

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 500;
$products = svcr_products();
$auditedProducts = 0;
$auditedImages = 0;
$suspects = [];

foreach ($products as $product) {
    if ($auditedProducts >= $limit) break;
    if (!is_array($product)) continue;
    $auditedProducts++;
    $images = is_array($product['images'] ?? null) ? $product['images'] : [];
    if ($images === []) {
        $single = trim((string)($product['image_url'] ?? ''));
        if ($single !== '') $images = [$single];
    }
    $position = 0;
    foreach ($images as $image) {
        $url = trim((string)$image);
        $position++;
        $auditedImages++;
        $risk = svqa_image_risk($product, $url, $position);
        if (in_array($risk['status'], ['medium', 'high'], true)) {
            $suspects[] = [
                'sku' => (string)($product['sku'] ?? ''),
                'product_id' => (string)($product['id'] ?? ''),
                'name' => (string)($product['name'] ?? ''),
                'category' => (string)($product['category'] ?? ''),
                'image_url' => $url,
                'position' => $position,
                'status' => $risk['status'],
                'risk' => $risk['risk'],
                'overlap' => $risk['overlap'],
                'reasons' => $risk['reasons'],
                'action' => 'review_before_delete_or_reimport',
            ];
        }
    }
}

usort($suspects, static fn(array $a, array $b): int => ($b['risk'] <=> $a['risk']) ?: ($a['sku'] <=> $b['sku']));

$result = [
    'ok' => true,
    'audit' => 'product-image-mismatch',
    'mode' => 'review_only',
    'audited_products' => $auditedProducts,
    'audited_images' => $auditedImages,
    'suspect_images' => count($suspects),
    'high_risk' => count(array_filter($suspects, static fn(array $row): bool => $row['status'] === 'high')),
    'medium_risk' => count(array_filter($suspects, static fn(array $row): bool => $row['status'] === 'medium')),
    'suspects' => array_slice($suspects, 0, 100),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(0);
