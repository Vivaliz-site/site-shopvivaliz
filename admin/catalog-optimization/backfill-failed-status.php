<?php

declare(strict_types=1);

/**
 * Backfill idempotente (uso único): reclassifica linhas antigas gravadas
 * como 'rejected' que na verdade foram falha TÉCNICA (chave ausente, erro
 * de rede, resposta malformada) para 'failed'.
 *
 * Discriminador seguro, sem precisar adivinhar por texto de erro: o
 * caminho de rejeição HUMANA real (admin_catalog.php, action=reject) só
 * faz `UPDATE ... SET status = 'rejected'` — NUNCA toca error_message.
 * Já o catch(CatalogAiApiException) em api/optimize_catalog.php SEMPRE
 * grava error_message junto com o status. Ou seja: toda linha 'rejected'
 * com error_message preenchido é, com certeza, uma falha técnica antiga —
 * não uma rejeição de conteúdo de verdade. Rodar mais de uma vez é
 * inofensivo (a segunda vez não encontra mais nada pra migrar).
 *
 * Uso: abrir uma vez, logado como admin:
 *   https://shopvivaliz.com.br/admin/catalog-optimization/backfill-failed-status.php
 */

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config_optimization.php';

header('Content-Type: application/json; charset=utf-8');

$db = catalog_ai_db();
if ($db === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Falha ao conectar ao banco de dados.']);
    exit;
}

try {
    $selectStmt = $db->query(
        "SELECT id, product_id, channel, provider_used
         FROM catalog_optimizations_staging
         WHERE status = 'rejected' AND error_message IS NOT NULL AND error_message <> ''"
    );
    $affectedRows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    $updateStmt = $db->prepare(
        "UPDATE catalog_optimizations_staging
         SET status = 'failed', updated_at = NOW()
         WHERE status = 'rejected' AND error_message IS NOT NULL AND error_message <> ''"
    );
    $updateStmt->execute();
    $reclassifiedCount = $updateStmt->rowCount();

    // Mesmo backfill em product_images_staging (AI Image Studio tem o
    // mesmo bug — ver docs/AGENTS.md 2026-08-05).
    $selectImagesStmt = $db->query(
        "SELECT id, product_id, image_type, provider_used
         FROM product_images_staging
         WHERE status = 'rejected' AND error_message IS NOT NULL AND error_message <> ''"
    );
    $affectedImageRows = $selectImagesStmt->fetchAll(PDO::FETCH_ASSOC);

    $updateImagesStmt = $db->prepare(
        "UPDATE product_images_staging
         SET status = 'failed', updated_at = NOW()
         WHERE status = 'rejected' AND error_message IS NOT NULL AND error_message <> ''"
    );
    $updateImagesStmt->execute();
    $reclassifiedImagesCount = $updateImagesStmt->rowCount();

    echo json_encode([
        'ok' => true,
        'catalog_optimizations_staging' => [
            'reclassificados_para_failed' => $reclassifiedCount,
            'itens' => $affectedRows,
        ],
        'product_images_staging' => [
            'reclassificados_para_failed' => $reclassifiedImagesCount,
            'itens' => $affectedImageRows,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[backfill-failed-status] Falha: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
