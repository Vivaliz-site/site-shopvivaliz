<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../includes/pdo-database.php';

$activeCoupons = [];

$builtins = [
    [
        'code' => 'PRIMEIRA10',
        'type' => 'percent',
        'value' => 10.0,
        'label' => 'Primeira compra: 10% de desconto',
        'active' => true
    ],
    [
        'code' => 'VIVALIZ10',
        'type' => 'percent',
        'value' => 10.0,
        'label' => '10% de desconto',
        'active' => true
    ],
    [
        'code' => 'BEMVINDO5',
        'type' => 'fixed',
        'value' => 5.0,
        'label' => 'R$ 5,00 de desconto',
        'active' => true
    ],
    [
        'code' => 'FRETEGRATIS',
        'type' => 'shipping',
        'value' => 0.0,
        'label' => 'Frete Gratis',
        'active' => true
    ]
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
        'SELECT code, description, discount_type, discount_value,
                starts_at, ends_at, expires_at, max_uses, used_count
         FROM coupons
         WHERE is_active = 1
           AND display_in_popup = 1
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (COALESCE(expires_at, ends_at) IS NULL OR COALESCE(expires_at, ends_at) >= NOW())
           AND (max_uses IS NULL OR max_uses = 0 OR used_count < max_uses)
         ORDER BY
           CASE discount_type
             WHEN ''percent'' THEN 1
             WHEN ''shipping'' THEN 2
             ELSE 3
           END,
           discount_value DESC,
           code ASC
         LIMIT 6'
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)($row['code'] ?? '')));
        if ($code === '') {
            continue;
        }

        $activeCoupons[] = [
            'code' => $code,
            'label' => trim((string)($row['description'] ?? '')) ?: $code,
            'type' => (string)($row['discount_type'] ?? 'percent'),
            'value' => (float)($row['discount_value'] ?? 0),
            'starts_at' => $row['starts_at'] ?? null,
            'ends_at' => ($row['expires_at'] ?? null) ?: ($row['ends_at'] ?? null),
            'source' => 'database',
        ];
    }
} catch (Throwable $e) {
    error_log('[coupons/active] db lookup failed: ' . $e->getMessage());
}

echo json_encode($activeCoupons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);