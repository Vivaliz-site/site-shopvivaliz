<?php
/**
 * API: Dados de A/B Testing para Dashboard
 * GET /api/admin/ab-testing-data.php?action=variants&page_id=homepage
 * GET /api/admin/ab-testing-data.php?action=pages
 * GET /api/admin/ab-testing-data.php?action=events&page_id=homepage&days=7
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/admin-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $action = $_GET['action'] ?? 'pages';
    $pageId = $_GET['page_id'] ?? null;
    $days = (int)($_GET['days'] ?? 7);

    $response = ['ok' => false, 'action' => $action, 'data' => []];

    switch ($action) {
        case 'pages':
            // Retornar lista de todas as páginas com testes ativos
            $pages = AdminHelpers::getActivePagesWithTests();
            $response['ok'] = true;
            $response['data'] = $pages;
            $response['count'] = count($pages);
            break;

        case 'variants':
            // Retornar variantes de uma página específica
            if (!$pageId) {
                throw new Exception('page_id parameter required');
            }

            $variants = AdminHelpers::getPageVariants($pageId);
            $winner = AdminHelpers::getWinnerVariant($pageId);

            $response['ok'] = true;
            $response['page_id'] = $pageId;
            $response['data'] = $variants;
            $response['count'] = count($variants);
            $response['winner'] = $winner;

            // Preparar dados para gráficos
            $response['chartData'] = self::formatChartData($variants);

            break;

        case 'events':
            // Retornar histórico de eventos
            if (!$pageId) {
                throw new Exception('page_id parameter required');
            }

            $events = AdminHelpers::getEventHistory($pageId, $days);
            $response['ok'] = true;
            $response['page_id'] = $pageId;
            $response['days'] = $days;
            $response['data'] = $events;
            $response['count'] = count($events);

            // Preparar dados para gráfico de série temporal
            $response['chartData'] = self::formatTimelineData($events);

            break;

        case 'compare':
            // Comparar duas variantes
            $variantA = (int)($_GET['variant_a'] ?? 0);
            $variantB = (int)($_GET['variant_b'] ?? 0);

            if (!$variantA || !$variantB) {
                throw new Exception('variant_a and variant_b parameters required');
            }

            $comparison = AdminHelpers::compareVariants($variantA, $variantB);
            $response['ok'] = true;
            $response['data'] = $comparison;

            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Formatar variantes para gráficos Chart.js
 */
function formatChartData(array $variants): array {
    $names = [];
    $ctr = [];
    $conversions = [];
    $revenue = [];
    $colors = ['#173B63', '#059669', '#d97706', '#7c3aed', '#ec4899'];

    foreach ($variants as $i => $v) {
        $names[] = $v['variant_name'];
        $ctr[] = (float)$v['ctr_percentage'];
        $conversions[] = (int)$v['conversions'];
        $revenue[] = (float)$v['revenue'];
    }

    return [
        'ctr' => [
            'labels' => $names,
            'datasets' => [
                [
                    'label' => 'CTR (%)',
                    'data' => $ctr,
                    'backgroundColor' => array_slice($colors, 0, count($names)),
                    'borderColor' => array_slice($colors, 0, count($names)),
                    'borderWidth' => 1
                ]
            ]
        ],
        'conversions' => [
            'labels' => $names,
            'datasets' => [
                [
                    'label' => 'Conversões',
                    'data' => $conversions,
                    'backgroundColor' => array_slice($colors, 0, count($names)),
                    'borderColor' => array_slice($colors, 0, count($names)),
                    'borderWidth' => 1
                ]
            ]
        ],
        'revenue' => [
            'labels' => $names,
            'datasets' => [
                [
                    'label' => 'Receita (R$)',
                    'data' => $revenue,
                    'backgroundColor' => array_slice($colors, 0, count($names)),
                    'borderColor' => array_slice($colors, 0, count($names)),
                    'borderWidth' => 1
                ]
            ]
        ]
    ];
}

/**
 * Formatar histórico de eventos para gráfico temporal
 */
function formatTimelineData(array $events): array {
    $timeline = [];

    foreach ($events as $e) {
        $date = $e['date'];
        if (!isset($timeline[$date])) {
            $timeline[$date] = [
                'impression' => 0,
                'click' => 0,
                'conversion' => 0
            ];
        }
        $timeline[$date][$e['event_type']] = (int)$e['count'];
    }

    $labels = array_keys($timeline);
    $impressions = [];
    $clicks = [];
    $conversions = [];

    foreach ($timeline as $data) {
        $impressions[] = $data['impression'];
        $clicks[] = $data['click'];
        $conversions[] = $data['conversion'];
    }

    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Impressões',
                'data' => $impressions,
                'borderColor' => '#173B63',
                'backgroundColor' => 'rgba(23, 59, 99, 0.1)',
                'borderWidth' => 2,
                'fill' => true,
                'tension' => 0.4
            ],
            [
                'label' => 'Cliques',
                'data' => $clicks,
                'borderColor' => '#059669',
                'backgroundColor' => 'rgba(5, 150, 105, 0.1)',
                'borderWidth' => 2,
                'fill' => true,
                'tension' => 0.4
            ],
            [
                'label' => 'Conversões',
                'data' => $conversions,
                'borderColor' => '#d97706',
                'backgroundColor' => 'rgba(217, 119, 6, 0.1)',
                'borderWidth' => 2,
                'fill' => true,
                'tension' => 0.4
            ]
        ]
    ];
}
