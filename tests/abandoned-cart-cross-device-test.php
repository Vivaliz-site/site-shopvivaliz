<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/src/Commerce/AbandonedCartRecoverySchema.php');
$tracker = file_get_contents($root . '/api/checkout/track-abandonment.php');
$sender = file_get_contents($root . '/scripts/send-abandoned-cart-emails.php');
$restore = file_get_contents($root . '/api/checkout/restore-abandonment.php');
$page = file_get_contents($root . '/recuperar-carrinho.php');

foreach ([$schema, $tracker, $sender, $restore, $page] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Unable to read cart recovery source\n");
        exit(1);
    }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$expect(str_contains($schema, 'recovery_token_hash CHAR(64)'), 'Recovery token hash column missing');
$expect(str_contains($schema, 'recovery_token_expires_at DATETIME'), 'Recovery token expiry column missing');
$expect(str_contains($tracker, "'sku' =>"), 'Tracker must persist SKU intent');
$expect(str_contains($tracker, "'quantity' =>"), 'Tracker must persist bounded quantity intent');
$expect(!str_contains($tracker, "'price' =>"), 'Tracker snapshot must not persist client-trusted price');
$expect(str_contains($tracker, 'IF(recovery_email_sent_at IS NULL, NULL, recovery_token_hash)'), 'Tracker must preserve already-issued restore token');
$expect(str_contains($sender, 'hash(\'sha256\', $restoreToken)'), 'Sender must store only token hash');
$expect(str_contains($sender, 'recuperar-carrinho#token='), 'Recovery token must use URL fragment');
$expect(!str_contains($sender, 'recuperar-carrinho?token='), 'Recovery token must not use query string');
$expect(str_contains($restore, 'hash(\'sha256\', $token)'), 'Restore endpoint must compare token by hash');
$expect(str_contains($restore, 'svcr_products()'), 'Restore endpoint must re-read canonical catalog');
$expect(str_contains($restore, '$stock <= 0'), 'Restore endpoint must reject unavailable stock');
$expect(str_contains($restore, '$price <= 0'), 'Restore endpoint must reject unavailable price');
$expect(!str_contains($restore, "'email' =>"), 'Restore response must not disclose email');
$expect(str_contains($page, 'history.replaceState'), 'Landing must remove token fragment from address bar');
$expect(str_contains($page, "window.location.replace('/carrinho')"), 'Landing must redirect to canonical cart after restore');

fwrite(STDOUT, "abandoned-cart-cross-device-test: ok\n");