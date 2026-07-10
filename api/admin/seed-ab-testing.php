<?php
/**
 * Seed Script: Gerar dados de teste para A/B Testing
 * GET /api/admin/seed-ab-testing.php
 *
 * Cria variantes de página e eventos de teste para demonstração do dashboard
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/admin-helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance()->getConnection();

    // Limpar dados antigos (opcional)
    $clearExisting = $_GET['clear'] === '1';
    if ($clearExisting) {
        $db->query("DELETE FROM ab_test_events");
        $db->query("DELETE FROM page_layout_variants");
    }

    // Dados de exemplo
    $pages = ['homepage', 'produto', 'checkout', 'categoria'];
    $variants = [
        ['name' => 'Control', 'type' => 'control'],
        ['name' => 'Variant A', 'type' => 'treatment'],
        ['name' => 'Variant B', 'type' => 'treatment']
    ];

    $created = 0;
    $events = 0;

    foreach ($pages as $pageId) {
        foreach ($variants as $variant) {
            // Criar variante
            $variantId = AdminHelpers::createPageVariant(
                $pageId,
                $variant['name'],
                $variant['type']
            );

            if ($variantId) {
                $created++;

                // Gerar dados simulados
                $impressions = rand(500, 5000);
                $clicks = (int)($impressions * rand(1, 8) / 100);
                $conversions = (int)($clicks * rand(5, 25) / 100);
                $revenue = $conversions * rand(50, 300);

                // Atualizar estatísticas
                $db->query("
                    UPDATE page_layout_variants
                    SET impressions = $impressions,
                        clicks = $clicks,
                        conversions = $conversions,
                        revenue = $revenue,
                        updated_at = NOW()
                    WHERE id = $variantId
                ");

                // Gerar eventos de teste
                $eventTypes = ['impression' => $impressions, 'click' => $clicks, 'conversion' => $conversions];
                foreach ($eventTypes as $type => $count) {
                    for ($i = 0; $i < $count; $i++) {
                        $sessionId = 'test-' . substr(md5(rand()), 0, 16);
                        AdminHelpers::logEvent($variantId, $pageId, $type, $sessionId);
                        $events++;
                    }
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Dados de teste criados com sucesso',
        'variants_created' => $created,
        'events_created' => $events,
        'pages_seeded' => $pages,
        'clear_existing' => $clearExisting
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
