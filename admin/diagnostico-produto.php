<?php

declare(strict_types=1);

/**
 * Diagnóstico read-only e admin-guarded pra confirmar, campo a campo, onde
 * vive a descrição/categoria/marca/specs REAIS de um produto — necessário
 * porque `products.description` pode legitimamente ser igual ao nome (o
 * próprio Tiny não tem descrição rica para alguns itens), enquanto
 * `olist_products.descricao_complementar` pode ter o texto rico de verdade.
 * Sem escrita nenhuma. Uso: ?product_id=1 ou ?olist_id=337702887
 */

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/pdo-database.php';

header('Content-Type: application/json; charset=utf-8');

$db = sv_pdo();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'erro' => 'Banco indisponível.']);
    exit;
}

$productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
$olistId = trim((string) ($_GET['olist_id'] ?? ''));

$productRow = null;
if ($productId > 0) {
    $stmt = $db->prepare('SELECT id, olist_id, sku, name, description FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $productRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($productRow && $olistId === '') {
        $olistId = (string) ($productRow['olist_id'] ?? '');
    }
}

$olistRow = null;
if ($olistId !== '') {
    $stmt = $db->prepare(
        'SELECT olist_id, sku, name, description, nome, descricao, descricao_complementar, marca, categoria
         FROM olist_products WHERE CAST(olist_id AS CHAR) = ? LIMIT 1'
    );
    $stmt->execute([$olistId]);
    $olistRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

echo json_encode(['ok' => true, 'products' => $productRow, 'olist_products' => $olistRow], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
