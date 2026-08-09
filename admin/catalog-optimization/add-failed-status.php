<?php

declare(strict_types=1);

/**
 * Migration idempotente (segura pra rodar mais de uma vez): adiciona o
 * status 'failed' ao ENUM de catalog_optimizations_staging e
 * product_images_staging, separado de 'rejected'.
 *
 * Motivo: até agora, qualquer falha TÉCNICA (chave de API ausente, erro de
 * rede/timeout, resposta malformada da IA) era gravada com status
 * 'rejected' — o MESMO status que um admin usa ao rejeitar de verdade o
 * conteúdo pelo botão "Rejeitar". Isso escondia 24 itens (14 ML + 10
 * Shopee) que falharam só por falta de chave de API atrás de um status que
 * parece rejeição de conteúdo definitiva, e que não seria reprocessado
 * automaticamente depois que as chaves foram configuradas.
 *
 * MySQL não tem "ADD VALUE TO ENUM" direto — precisa de MODIFY COLUMN
 * reescrevendo a lista inteira. É seguro porque só ADICIONA um valor válido
 * novo; todos os valores antigos (pending/approved/rejected) continuam
 * válidos e nenhuma linha existente é alterada por este passo (o backfill
 * de reclassificação é um UPDATE separado, feito depois, no código chamador
 * ou manualmente).
 *
 * Uso: abrir uma vez, logado como admin:
 *   https://shopvivaliz.com.br/admin/catalog-optimization/add-failed-status.php
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

$alterations = [
    'catalog_optimizations_staging' => "ALTER TABLE `catalog_optimizations_staging` MODIFY `status` ENUM('pending','approved','rejected','failed') NOT NULL DEFAULT 'pending'",
    'product_images_staging' => "ALTER TABLE `product_images_staging` MODIFY `status` ENUM('pending','approved','rejected','failed') NOT NULL DEFAULT 'pending'",
];

$results = [];
$allOk = true;

foreach ($alterations as $table => $sql) {
    try {
        $db->exec($sql);
        $results[$table] = ['ok' => true];
    } catch (Throwable $e) {
        $allOk = false;
        $results[$table] = ['ok' => false, 'erro' => $e->getMessage()];
        error_log('[add-failed-status] Falha em ' . $table . ': ' . $e->getMessage());
    }
}

http_response_code($allOk ? 200 : 500);
echo json_encode(['ok' => $allOk, 'tabelas' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
