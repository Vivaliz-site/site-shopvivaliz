<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/liz-knowledge-context.php';

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Informe o parametro q para testar o contexto editorial da Liz.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$articles = sv_liz_knowledge_context($query, 3);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'query' => $query,
    'count' => count($articles),
    'articles' => $articles,
    'prompt_block' => sv_liz_knowledge_prompt_block($articles),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
