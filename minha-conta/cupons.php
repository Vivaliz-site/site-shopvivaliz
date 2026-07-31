<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/account-chrome.php';
require_once __DIR__ . '/../includes/pdo-database.php';

$svAccountUser = sv_account_require_login();
$svAccountPageTitle = 'Meus Cupons';
$svAccountActive = 'cupons';

$coupons = [];
try {
    $pdo = sv_pdo();
    $stmt = $pdo->query(
        "SELECT code, description, discount_type, discount_value, ends_at
         FROM coupons
         WHERE is_active = 1 AND (ends_at IS NULL OR ends_at >= NOW())
         ORDER BY ends_at IS NULL, ends_at ASC
         LIMIT 50"
    );
    $coupons = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[MinhaConta] cupons query failed: ' . $e->getMessage());
}

$labelFor = static function (array $c): string {
    if (trim((string)($c['description'] ?? '')) !== '') {
        return (string)$c['description'];
    }
    return match ($c['discount_type']) {
        'percent' => 'Desconto ' . rtrim(rtrim(number_format((float)$c['discount_value'], 2, ',', '.'), '0'), ',') . '%',
        'fixed' => 'Desconto R$ ' . number_format((float)$c['discount_value'], 2, ',', '.'),
        'shipping' => 'Frete Grátis',
        default => 'Desconto aplicado',
    };
};

require __DIR__ . '/../includes/account-chrome-top.php';
?>
<h1>Meus Cupons</h1>
<p class="sv-subtitle">Cupons ativos disponíveis para uso no checkout.</p>

<?php if (empty($coupons)): ?>
    <p style="color:#999;">Nenhum cupom ativo no momento.</p>
<?php else: ?>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
        <?php foreach ($coupons as $c): ?>
            <div style="border:1px dashed #173b63; border-radius:8px; padding:16px; background:#f7f9fc;">
                <div style="font-size:18px; font-weight:700; letter-spacing:1px; color:#173b63;"><?php echo htmlspecialchars($c['code']); ?></div>
                <div style="font-size:14px; margin-top:4px;"><?php echo htmlspecialchars($labelFor($c)); ?></div>
                <?php if (!empty($c['ends_at'])): ?>
                    <div style="font-size:12px; color:#666;">Válido até <?php echo date('d/m/Y', strtotime($c['ends_at'])); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/account-chrome-bottom.php'; ?>
