<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

ml_load_env();
$tokens   = ml_tokens();
$sellerId = getenv('ML_SELLER_ID') ?: ($tokens['user_id'] ?? '');

if (!$sellerId) {
    ml_json(['error' => 'seller_id_missing', 'message' => 'ML_SELLER_ID não configurado.'], 400);
}

$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$status = in_array($_GET['status'] ?? '', ['active','paused','closed','under_review'], true)
    ? $_GET['status'] : 'active';

$data = ml_get("/users/$sellerId/items/search?status=$status&offset=$offset&limit=$limit");

if (isset($data['error']) && $data['error'] === 'sem_token') {
    ml_json(['error' => 'not_connected'], 401);
}

// Busca detalhes dos itens (IDs retornados)
$items   = [];
$ids     = $data['results'] ?? [];
if (!empty($ids)) {
    $chunk = implode(',', array_slice($ids, 0, 20));
    $detail = ml_get("/items?ids=$chunk&attributes=id,title,status,price,available_quantity,thumbnail,permalink");
    foreach ($detail as $item) {
        if (is_array($item) && isset($item['body'])) $items[] = $item['body'];
    }
}

ml_json([
    'total'   => $data['paging']['total'] ?? 0,
    'offset'  => $offset,
    'limit'   => $limit,
    'status'  => $status,
    'items'   => $items,
]);
