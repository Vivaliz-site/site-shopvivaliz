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

$data = ml_get("/orders/search?seller=$sellerId&sort=date_desc&offset=$offset&limit=$limit");

if (isset($data['error']) && $data['error'] === 'sem_token') {
    ml_json(['error' => 'not_connected'], 401);
}

$orders = [];
foreach ($data['results'] ?? [] as $o) {
    $orders[] = [
        'id'          => $o['id']             ?? null,
        'status'      => $o['status']         ?? null,
        'total'       => $o['total_amount']   ?? null,
        'currency'    => $o['currency_id']    ?? 'BRL',
        'date'        => $o['date_created']   ?? null,
        'buyer'       => $o['buyer']['nickname'] ?? null,
        'items_count' => count($o['order_items'] ?? []),
        'item_title'  => $o['order_items'][0]['item']['title'] ?? null,
    ];
}

ml_json([
    'total'  => $data['paging']['total'] ?? 0,
    'offset' => $offset,
    'limit'  => $limit,
    'orders' => $orders,
]);
