<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$code = strtoupper(trim($body['code'] ?? ''));

if ($code === '') {
    echo json_encode(['ok'=>false,'error'=>'Código vazio']); exit;
}

/* ── Carrega cupons do banco de dados se disponível ── */
function loadDbCoupons(string $code): ?array {
    $host = getenv('DB_HOST') ?: '';
    $name = getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    if (!$host || !$name || !$user) return null;
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        $st = $pdo->prepare(
            'SELECT code, discount_type, discount_value, min_order_value, expires_at, max_uses, used_count, is_active
             FROM coupons WHERE code = :code LIMIT 1'
        );
        $st->execute([':code' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!$row['is_active']) return ['ok'=>false,'error'=>'Cupom desativado'];
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) return ['ok'=>false,'error'=>'Cupom expirado'];
        if ($row['max_uses'] > 0 && $row['used_count'] >= $row['max_uses']) return ['ok'=>false,'error'=>'Cupom esgotado'];
        $label = match($row['discount_type']) {
            'pct', 'percent' => 'Desconto ' . (int)$row['discount_value'] . '%',
            'fixed' => 'Desconto R$ ' . number_format((float)$row['discount_value'], 2, ',', '.'),
            'frete', 'shipping' => 'Frete Grátis',
            default => 'Desconto aplicado',
        };
        return [
            'ok'    => true,
            'code'  => $code,
            'type'  => $row['discount_type'],
            'value' => (float)$row['discount_value'],
            'label' => $label,
            'min_order_value' => (float)($row['min_order_value'] ?? 0),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/* ── Fallback: cupons em .env / arquivo de config ── */
function loadFileCoupons(string $code): ?array {
    $cfg_path = __DIR__ . '/../../config/coupons.json';
    if (!is_file($cfg_path)) return null;
    $cfg = json_decode(file_get_contents($cfg_path), true) ?: [];
    $c = $cfg[$code] ?? null;
    if (!$c) return null;
    if (isset($c['expires_at']) && strtotime($c['expires_at']) < time()) return ['ok'=>false,'error'=>'Cupom expirado'];
    return ['ok'=>true] + $c;
}

/* ── Cupons hardcoded para demonstração / fallback final ── */
function builtinCoupons(string $code): ?array {
    $builtins = [
        'PRIMEIRA10' => ['type'=>'percent', 'value'=>10, 'label'=>'Primeira compra: 10% de desconto'],
        'VIVALIZ10'   => ['type'=>'percent', 'value'=>10, 'label'=>'Primeira compra: 10% de desconto'],
        'BEMVINDO5'   => ['type'=>'fixed', 'value'=>5,  'label'=>'Desconto R$ 5,00'],
        'FRETEGRATIS' => ['type'=>'frete', 'value'=>0,  'label'=>'Frete Grátis'],
    ];
    if (!isset($builtins[$code])) return null;
    return ['ok'=>true, 'code'=>$code] + $builtins[$code];
}

/* ── Resolução em cascata ── */
$result = loadDbCoupons($code)
       ?? loadFileCoupons($code)
       ?? builtinCoupons($code)
       ?? ['ok'=>false, 'error'=>'Cupom inválido'];

http_response_code($result['ok'] ? 200 : 422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
