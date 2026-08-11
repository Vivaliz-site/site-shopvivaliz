<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../process_item.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$targetChannel = strtolower(trim((string)($_GET['target_channel'] ?? 'site')));
$limit = max(1, min(50, (int)($_GET['limit'] ?? 12)));
$profiles = ai_studio_channel_profiles();

if (!isset($profiles[$targetChannel])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Marketplace de destino invalido.']);
    exit;
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
    exit;
}

svcp_ensure_schema($db);

try {
    // Nao seleciona colunas opcionais (como products.category), porque o schema
    // de producao nao garante esses campos. O contexto adicional e resolvido
    // depois por ai_studio_fetch_product(), que ja conhece os aliases legados.
    //
    // Falhas/rejeicoes podem ser tentadas novamente. Qualquer imagem ainda
    // pendente, enviada ou publicada bloqueia nova geracao para o mesmo canal.
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.image_url, p.sku '
        . 'FROM products p '
        . 'WHERE NOT EXISTS ('
        . ' SELECT 1 FROM product_images_staging s '
        . ' WHERE s.product_id = p.id '
        . ' AND s.target_channels_json LIKE ? '
        . " AND COALESCE(s.status, '') NOT IN ('failed','rejected')"
        . ') '
        . 'ORDER BY p.id ASC LIMIT ' . (int)$limit
    );
    $stmt->execute(['%"' . $targetChannel . '"%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $productId = (int)($row['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $context = ai_studio_fetch_product($db, $productId) ?? [];
        $items[] = [
            'id' => $productId,
            'name' => trim((string)($row['name'] ?? '')),
            'sku' => trim((string)($row['sku'] ?? '')),
            'image_url' => trim((string)($row['image_url'] ?? '')),
            'category' => trim((string)($context['category'] ?? '')),
            'has_image' => trim((string)($context['image_ref'] ?? '')) !== '',
        ];
    }

    echo json_encode([
        'success' => true,
        'target_channel' => $targetChannel,
        'items' => $items,
        'count' => count($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[ai-image-studio] pending_candidates: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nao foi possivel carregar os produtos para geracao.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
