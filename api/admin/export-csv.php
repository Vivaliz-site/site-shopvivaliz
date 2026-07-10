<?php
/**
 * Export CSV: Exportar dados de A/B Testing
 * GET /api/admin/export-csv.php?page_id=homepage
 * GET /api/admin/export-csv.php?action=all
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/admin-helpers.php';

try {
    $action = $_GET['action'] ?? 'page';
    $pageId = $_GET['page_id'] ?? null;

    $filename = 'ab-testing-export-' . date('Y-m-d-His');

    // Configurar headers para download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    if ($action === 'page' && $pageId) {
        // Exportar variantes de uma página
        exportPageVariants($pageId, $output);
    } elseif ($action === 'all') {
        // Exportar todas as variantes
        exportAllVariants($output);
    } else {
        throw new Exception('Invalid action or missing parameters');
    }

    fclose($output);

} catch (\Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Exportar variantes de uma página
 */
function exportPageVariants(string $pageId, $output) {
    // Cabeçalho
    fputcsv($output, [
        'Page ID',
        'Variant Name',
        'Type',
        'Impressions',
        'Clicks',
        'CTR %',
        'Conversions',
        'Conversion Rate %',
        'Revenue (R$)',
        'AOV (R$)',
        'Status',
        'Created At'
    ]);

    $variants = AdminHelpers::getPageVariants($pageId);

    foreach ($variants as $v) {
        fputcsv($output, [
            $v['page_id'],
            $v['variant_name'],
            $v['variant_type'],
            $v['impressions'],
            $v['clicks'],
            $v['ctr_percentage'],
            $v['conversions'],
            $v['conversion_rate'],
            $v['revenue'],
            $v['avg_order_value'] ?? 0,
            $v['status'],
            $v['created_at']
        ]);
    }
}

/**
 * Exportar todas as variantes
 */
function exportAllVariants($output) {
    // Cabeçalho
    fputcsv($output, [
        'Page ID',
        'Variant Name',
        'Type',
        'Impressions',
        'Clicks',
        'CTR %',
        'Conversions',
        'Conversion Rate %',
        'Revenue (R$)',
        'AOV (R$)',
        'Status',
        'Created At'
    ]);

    $pages = AdminHelpers::getActivePagesWithTests();

    foreach ($pages as $page) {
        $variants = AdminHelpers::getPageVariants($page['page_id']);
        foreach ($variants as $v) {
            fputcsv($output, [
                $v['page_id'],
                $v['variant_name'],
                $v['variant_type'],
                $v['impressions'],
                $v['clicks'],
                $v['ctr_percentage'],
                $v['conversions'],
                $v['conversion_rate'],
                $v['revenue'],
                $v['avg_order_value'] ?? 0,
                $v['status'],
                $v['created_at']
            ]);
        }
    }
}
