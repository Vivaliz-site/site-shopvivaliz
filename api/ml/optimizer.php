<?php
declare(strict_types=1);
/**
 * POST /api/ml/optimizer
 * Analisa título e descrição de um produto e sugere melhorias
 * para o algoritmo de busca do Mercado Livre.
 *
 * Body JSON:
 *   { "title": "...", "description": "...", "category": "...", "price": 0 }
 *
 * Retorna sugestões de: título otimizado, palavras-chave, categoria ML,
 * checklist de qualidade e score estimado.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function svmlopt_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function svmlopt_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function svmlopt_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json_body']);
    exit;
}

$title       = trim((string)($body['title']       ?? ''));
$description = trim((string)($body['description'] ?? ''));
$category    = trim((string)($body['category']    ?? ''));
$price       = (float)($body['price'] ?? 0);

if ($title === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'title_required']);
    exit;
}

/* ------------------------------------------------------------------ *
 * Banco de palavras-chave de alto tráfego por categoria (ML Brasil)   *
 * ------------------------------------------------------------------ */
$KEYWORDS_BY_CATEGORY = [
    'rodízios'    => ['rodizio', 'roldana', 'giratório', 'com freio', 'sem freio', 'gel', 'silicone', 'anti-risco', 'kit', 'conjunto', 'mm'],
    'parafusos'   => ['parafuso', 'inox', 'aço', 'rosca', 'sextavado', 'philips', 'kit', 'caixa', 'galvanizado'],
    'porcas'      => ['porca', 'inox', 'galvanizada', 'sextavada', 'borboleta', 'kit', 'conjunto'],
    'arruelas'    => ['arruela', 'plana', 'pressão', 'inox', 'kit'],
    'ferramentas' => ['ferramenta', 'profissional', 'chave', 'alicate', 'kit', 'conjunto', 'jogo'],
    'default'     => ['kit', 'conjunto', 'original', 'novo', 'qualidade', 'profissional'],
];

$catLower = svmlopt_lower($category);
$keywords = $KEYWORDS_BY_CATEGORY['default'];
foreach ($KEYWORDS_BY_CATEGORY as $key => $kws) {
    if ($key !== 'default' && str_contains($catLower, $key)) {
        $keywords = array_merge($kws, $KEYWORDS_BY_CATEGORY['default']);
        break;
    }
}

/* ------------------------------------------------------------------ *
 * Mapeamento de categoria ML                                           *
 * ------------------------------------------------------------------ */
$ML_CATEGORIES = [
    'rodízios'    => ['id' => 'MLB5672',  'name' => 'Rodízios e Roldanas'],
    'parafusos'   => ['id' => 'MLB39381', 'name' => 'Parafusos e Porcas'],
    'porcas'      => ['id' => 'MLB39381', 'name' => 'Parafusos e Porcas'],
    'arruelas'    => ['id' => 'MLB39381', 'name' => 'Parafusos e Porcas'],
    'ferramentas' => ['id' => 'MLB1500',  'name' => 'Ferramentas'],
    'default'     => ['id' => 'MLB1574',  'name' => 'Peças e Acessórios'],
];
$mlCategory = $ML_CATEGORIES['default'];
foreach ($ML_CATEGORIES as $key => $cat) {
    if ($key !== 'default' && str_contains($catLower, $key)) { $mlCategory = $cat; break; }
}

/* ------------------------------------------------------------------ *
 * Análise do título                                                    *
 * ------------------------------------------------------------------ */
$titleWords = svmlopt_lower($title);
$titleLen   = svmlopt_len($title);
$issues     = [];
$suggestions = [];

// Regra 1: comprimento ideal 40-60 chars
if ($titleLen < 25) {
    $issues[] = 'Título muito curto — adicione marca, material ou dimensões.';
} elseif ($titleLen > 60) {
    $issues[] = 'Título excede 60 caracteres — o ML trunca na busca.';
} else {
    $suggestions[] = 'Comprimento do título está no intervalo ideal.';
}

// Regra 2: presença de quantidade/especificação numérica (ex: 10x, 35mm, Kit 8)
if (!preg_match('/\d/', $title)) {
    $issues[] = 'Inclua especificações numéricas (ex: quantidade, diâmetro, modelo).';
}

// Regra 3: capitalização — ML prefere Título Case sem todas maiúsculas
if ($title === mb_strtoupper($title)) {
    $issues[] = 'Evite CAPS LOCK completo — use Título Case para melhor CTR.';
}

// Regra 4: palavras-chave relevantes presentes
$foundKws = [];
$missingKws = [];
foreach (array_slice($keywords, 0, 6) as $kw) {
    if (str_contains($titleWords, mb_strtolower($kw))) {
        $foundKws[] = $kw;
    } else {
        $missingKws[] = $kw;
    }
}
if ($foundKws) {
    $suggestions[] = 'Palavras-chave já no título: ' . implode(', ', $foundKws) . '.';
}
if ($missingKws) {
    $issues[] = 'Considere adicionar ao título: ' . implode(', ', array_slice($missingKws, 0, 3)) . '.';
}

// Regra 5: preço razoável para ML (mínimo R$ 5)
if ($price > 0 && $price < 5) {
    $issues[] = 'Preço abaixo de R$ 5,00 — valor mínimo aceito pelo ML.';
} elseif ($price === 0.0) {
    $issues[] = 'Preço não definido — necessário para publicar.';
}

// Regra 6: descrição presente
if ($description === '') {
    $issues[] = 'Descrição ausente — anúncios com descrição convertem melhor.';
} elseif (mb_strlen($description) < 100) {
    $issues[] = 'Descrição muito curta — recomendado mínimo 100 caracteres.';
} else {
    $suggestions[] = 'Descrição presente com comprimento adequado.';
}

/* ------------------------------------------------------------------ *
 * Gera título otimizado                                                *
 * ------------------------------------------------------------------ */
// Tenta adicionar a palavra-chave principal se não estiver no título
$optimizedTitle = $title;
if (count($missingKws) > 0 && $titleLen < 55) {
    $toAdd = current($missingKws);
    $candidate = $optimizedTitle . ' ' . ucfirst($toAdd);
    if (mb_strlen($candidate) <= 60) {
        $optimizedTitle = $candidate;
    }
}
// Trunca para segurança
$optimizedTitle = mb_substr($optimizedTitle, 0, 60);

/* ------------------------------------------------------------------ *
 * Score de qualidade do anúncio (0-100)                               *
 * ------------------------------------------------------------------ */
$score = 100;
$score -= count($issues) * 12;
$score = max(0, min(100, $score));
$scoreLabel = $score >= 80 ? 'ótimo' : ($score >= 55 ? 'bom' : ($score >= 35 ? 'regular' : 'fraco'));

/* ------------------------------------------------------------------ *
 * Resposta                                                             *
 * ------------------------------------------------------------------ */
echo json_encode([
    'ok'              => true,
    'input'           => [
        'title'       => $title,
        'title_len'   => $titleLen,
        'has_desc'    => $description !== '',
        'price'       => $price,
        'category'    => $category,
    ],
    'optimized_title' => $optimizedTitle,
    'ml_category'     => $mlCategory,
    'keywords'        => [
        'relevant'    => $keywords,
        'found_in_title'   => $foundKws,
        'missing_from_title' => $missingKws,
    ],
    'quality' => [
        'score'       => $score,
        'label'       => $scoreLabel,
        'issues'      => $issues,
        'suggestions' => $suggestions,
    ],
    'ready_to_publish' => count($issues) === 0 && $price >= 5,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
