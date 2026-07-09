<?php
/**
 * API Endpoint: Resolver qual variante servir para um visitante
 *
 * GET /api/catalog/ab-variant.php?page_id=homepage
 * Response: {ok: bool, variant_id, variant_name, config: {...}, session_id}
 *
 * O cliente deve:
 * 1. Chamar este endpoint
 * 2. Setar cookie com variant_id
 * 3. Renderizar usando config retornado
 * 4. Rastrear impressões via ab-tracking.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/LayoutManager.php';

use Core\Database;
use Core\LayoutManager;

header('Content-Type: application/json; charset=utf-8');

try {
    $pageId = $_GET['page_id'] ?? null;

    if (!$pageId) {
        throw new Exception('page_id parameter required');
    }

    // Validar formato
    if (!preg_match('/^[a-z0-9-]+$/', $pageId)) {
        throw new Exception('Invalid page_id format');
    }

    // Gerar session hash determinístico
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $sessionHash = md5($ip . '|' . $userAgent);

    // Obter cookie se já existe
    $existingVariantId = $_COOKIE["ab_variant_{$pageId}"] ?? null;

    $db = Database::connect();
    $layoutManager = new LayoutManager($db, 0);

    $variant = null;

    // Se já tem cookie, usar mesma variante
    if ($existingVariantId) {
        $variants = $layoutManager->getVariants($pageId, true);
        foreach ($variants as $v) {
            if ($v['id'] == $existingVariantId) {
                $variant = [
                    'variant_id' => $v['id'],
                    'variant_name' => $v['variant_name'],
                    'variant_key' => $v['variant_key'],
                    'config' => $v['config']
                ];
                break;
            }
        }
    }

    // Se não, selecionar baseado em hash (determinístico)
    if (!$variant) {
        $variant = $layoutManager->selectVariantForRequest($pageId, $sessionHash);
    }

    if (!$variant) {
        throw new Exception("No variants available for {$pageId}");
    }

    // Setar cookie para futuras requisições
    setcookie(
        "ab_variant_{$pageId}",
        $variant['variant_id'],
        time() + (30 * 24 * 60 * 60),  // 30 dias
        '/',
        '',
        false,
        true  // httponly
    );

    // Registrar impressão
    $layoutManager->recordImpression($variant['variant_id']);

    echo json_encode([
        'ok' => true,
        'page_id' => $pageId,
        'variant_id' => $variant['variant_id'],
        'variant_name' => $variant['variant_name'],
        'variant_key' => $variant['variant_key'],
        'config' => $variant['config'],
        'session_id' => $sessionHash
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
