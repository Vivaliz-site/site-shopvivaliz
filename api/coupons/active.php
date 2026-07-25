<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/coupons.php';
require_once __DIR__ . '/../../includes/pdo-database.php';

$activeCoupons = [];

// Cupons builtin
$builtins = [
    ['code' => 'PRIMEIRA10', 'type' => 'percent', 'value' => 10.0, 'label' => 'Primeira compra: 10% de desconto', 'active' => true],
    ['code' => 'VIVALIZ10', 'type' => 'percent', 'value' => 10.0, 'label' => '10% de desconto', 'active' => true],
    ['code' => 'BEMVINDO5', 'type' => 'fixed', 'value' => 5.0, 'label' => 'R$ 5,00 de desconto', 'active' => true],
    ['code' => 'FRETEGRATIS', 'type' => 'shipping', 'value' => 0.0, 'label' => 'Frete Grátis', 'active' => true],
];

foreach ($builtins as $coupon) {
    $activeCoupons[] = [
        'code' => $coupon['code'],
        'label' => $coupon['label'],
        'type' => $coupon['type'],
        'value' => $coupon['value'],
        'source' => 'builtin',
    ];
}

// Cupons do banco (apenas cupons marcados para popup)
try {
    $pdo = sv_pdo();
    $stmt = $pdo->query(
        'SELECT code, description, discount_type, discount_value
         FROM coupons
         WHERE is_active = 1 AND display_in_popup = 1 AND (ends_at IS NULL OR ends_at > NOW())
         ORDER BY RAND()
         LIMIT 5'
    );
    $dbCoupons = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($dbCoupons as $row) {
        $activeCoupons[] = [
            'code' => $row['code'] ?? '',
            'label' => $row['description'] ?? $row['code'],
            'type' => $row['discount_type'] ?? 'percent',
            'value' => (float)($row['discount_value'] ?? 0),
            'source' => 'database',
        ];
    }
} catch (Throwable $e) {
    error_log('[coupons/active] db lookup failed: ' . $e->getMessage());
}

// Remover duplicatas
$seen = [];
$unique = [];
foreach ($activeCoupons as $coupon) {
    if (!isset($seen[$coupon['code']])) {
        $unique[] = $coupon;
        $seen[$coupon['code']] = true;
    }
}

echo json_encode($unique, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
