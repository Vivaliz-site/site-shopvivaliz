<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function gms_root(): string
{
    return dirname(__DIR__, 2);
}

function gms_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function gms_read_json(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function gms_orders(): array
{
    $dir = gms_root() . '/storage/orders';
    if (!is_dir($dir)) {
        return [];
    }
    $orders = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $decoded = gms_read_json($file);
        if (is_array($decoded)) {
            $orders[] = $decoded;
        }
    }
    return $orders;
}

function gms_feedback(): array
{
    $dir = gms_root() . '/storage/support-feedback';
    if (!is_dir($dir)) {
        return [];
    }
    $feedback = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (basename($file) === 'latest-summary.json') {
            continue;
        }
        $decoded = gms_read_json($file);
        if (is_array($decoded)) {
            $feedback[] = $decoded;
        }
    }
    return $feedback;
}

function gms_customer_key(array $order): string
{
    $email = strtolower(trim((string)($order['customer']['email'] ?? '')));
    if ($email !== '') {
        return $email;
    }
    $name = strtolower(trim((string)($order['customer']['name'] ?? '')));
    if ($name !== '') {
        return $name;
    }
    return trim((string)($order['order_number'] ?? 'unknown'));
}

function gms_display_name(array $order): string
{
    $name = trim((string)($order['customer']['name'] ?? ''));
    if ($name === '') {
        return 'Cliente';
    }
    $parts = preg_split('/\s+/', $name) ?: [$name];
    return trim((string)$parts[0]);
}

function gms_current_month(string $createdAt): bool
{
    return $createdAt !== '' && str_starts_with($createdAt, date('Y-m'));
}

$orders = gms_orders();
$feedback = gms_feedback();

$leaderboard = [];
foreach ($orders as $order) {
    if (!is_array($order) || !gms_current_month((string)($order['created_at'] ?? ''))) {
        continue;
    }
    $key = gms_customer_key($order);
    if ($key === '') {
        continue;
    }
    if (!isset($leaderboard[$key])) {
        $leaderboard[$key] = [
            'display_name' => gms_display_name($order),
            'orders_count' => 0,
            'total_spent' => 0.0,
            'last_order_at' => '',
        ];
    }
    $leaderboard[$key]['orders_count']++;
    $leaderboard[$key]['total_spent'] += (float)($order['total'] ?? 0);
    $createdAt = (string)($order['created_at'] ?? '');
    if ($createdAt > $leaderboard[$key]['last_order_at']) {
        $leaderboard[$key]['last_order_at'] = $createdAt;
    }
}

usort($leaderboard, static function (array $a, array $b): int {
    return [$b['orders_count'], $b['total_spent'], $b['last_order_at']] <=> [$a['orders_count'], $a['total_spent'], $a['last_order_at']];
});

$monthlyOrders = array_filter($orders, static fn(array $order): bool => gms_current_month((string)($order['created_at'] ?? '')));
$userCount = count($leaderboard);
$ordersCount = count($orders);
$monthlyOrdersCount = count($monthlyOrders);
$feedbackCount = count($feedback);

$badges = [
    [
        'id' => 'primeira-compra',
        'title' => 'Primeira compra',
        'earned' => $ordersCount >= 1,
        'progress' => min(100, (int)(($ordersCount / 1) * 100)),
        'description' => 'Conquistado na primeira ordem registrada.',
    ],
    [
        'id' => 'cliente-fiel',
        'title' => 'Cliente fiel',
        'earned' => $ordersCount >= 3,
        'progress' => min(100, (int)(($ordersCount / 3) * 100)),
        'description' => 'Aparece quando o cliente chega a 3 pedidos.',
    ],
    [
        'id' => 'avaliador',
        'title' => 'Avaliador',
        'earned' => $feedbackCount >= 1,
        'progress' => min(100, (int)(($feedbackCount / 1) * 100)),
        'description' => 'Liberado quando o primeiro feedback entra no sistema.',
    ],
    [
        'id' => 'embaixador',
        'title' => 'Embaixador',
        'earned' => $ordersCount >= 5 && $feedbackCount >= 2,
        'progress' => min(100, (int)(($ordersCount / 5) * 70 + ($feedbackCount / 2) * 30)),
        'description' => 'Exige consistencia de compras e opinioes.',
    ],
    [
        'id' => 'top-do-mes',
        'title' => 'Top do mes',
        'earned' => $monthlyOrdersCount >= 3,
        'progress' => min(100, (int)(($monthlyOrdersCount / 3) * 100)),
        'description' => 'Recompensa o ritmo mensal de pedidos.',
    ],
];

$summary = [
    'generated_at' => date('c'),
    'orders_count' => $ordersCount,
    'monthly_orders_count' => $monthlyOrdersCount,
    'feedback_count' => $feedbackCount,
    'active_customers_count' => $userCount,
    'badges_earned_count' => count(array_filter($badges, static fn(array $badge): bool => (bool)$badge['earned'])),
    'leaderboard_size' => min(10, count($leaderboard)),
];

$payload = [
    'ok' => true,
    'summary' => $summary,
    'badges' => $badges,
    'leaderboard' => array_slice($leaderboard, 0, 10),
    'rewards' => [
        'checkout_gift' => 'Selo de destaque liberado por compras recorrentes.',
        'priority_support' => 'Atendimento priorizado para clientes com alta participacao.',
        'exclusive_drop' => 'Lote exclusivo em campanhas futuras para perfis engajados.',
    ],
];

$dir = gms_root() . '/storage/gamification';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
@file_put_contents($dir . '/latest-summary.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

gms_json(200, $payload);
