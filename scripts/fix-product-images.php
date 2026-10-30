<?php
declare(strict_types=1);

/**
 * RETIRED: this legacy repair directly rewrote storage/products-cache-ativos.json
 * outside the Amazon-991 ERP reconciliation lock. Any stale snapshot could
 * revert newer prices/stock or leave a partial file. No longer executable
 * by HTTP, CLI, cron or maintenance agents.
 *
 * Update the authoritative Olist/Tiny ERP record through the supported API,
 * then let the signed webhook or shopvivaliz-catalog-reconcile.service publish
 * the catalog. Image-only repairs belong in the guarded image publisher.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(410);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'legacy_catalog_repair_retired']);
} else {
    fwrite(STDERR, "legacy_catalog_repair_retired: use Olist/Tiny ERP v3 and official reconciliation\n");
}
exit(78);
