<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
require_once __DIR__ . '/../src/Commerce/AbandonedCartRecoverySchema.php';

$days = 30;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--days=(\d+)$/', (string)$arg, $m)) {
        $days = max(1, min(365, (int)$m[1]));
    }
}

$pdo = sv_pdo();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Banco indisponivel.\n");
    exit(1);
}
sv_account_ensure_schema();
svacr_ensure_restore_schema($pdo);

$stmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS captured,
        SUM(recovery_email_sent_at IS NOT NULL) AS emails_sent,
        SUM(restored_at IS NOT NULL) AS carts_restored,
        SUM(recovered_at IS NOT NULL) AS purchases_recovered,
        SUM(recovery_email_sent_at IS NOT NULL AND restored_at IS NOT NULL) AS restored_after_email,
        SUM(recovery_email_sent_at IS NOT NULL AND recovered_at IS NOT NULL) AS recovered_after_email
     FROM checkout_abandonments
     WHERE created_at >= (NOW() - INTERVAL :days DAY)'
);
$stmt->execute([':days' => $days]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$captured = (int)($row['captured'] ?? 0);
$sent = (int)($row['emails_sent'] ?? 0);
$restored = (int)($row['carts_restored'] ?? 0);
$recovered = (int)($row['purchases_recovered'] ?? 0);
$restoredAfterEmail = (int)($row['restored_after_email'] ?? 0);
$recoveredAfterEmail = (int)($row['recovered_after_email'] ?? 0);

$rate = static fn(int $num, int $den): float => $den > 0 ? round(($num / $den) * 100, 2) : 0.0;

$report = [
    'window_days' => $days,
    'captured' => $captured,
    'emails_sent' => $sent,
    'carts_restored' => $restored,
    'purchases_recovered' => $recovered,
    'restored_after_email' => $restoredAfterEmail,
    'recovered_after_email' => $recoveredAfterEmail,
    'email_to_restore_rate_pct' => $rate($restoredAfterEmail, $sent),
    'email_to_purchase_rate_pct' => $rate($recoveredAfterEmail, $sent),
    'restore_to_purchase_rate_pct' => $rate($recoveredAfterEmail, $restoredAfterEmail),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;