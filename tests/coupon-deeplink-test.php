<?php
declare(strict_types=1);

function cd_assert(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$js = (string)file_get_contents($root . '/js/first-purchase-popup-v1.js');
$product = (string)file_get_contents($root . '/produto.php');
$catalog = (string)file_get_contents($root . '/catalogo.php');
$home = (string)file_get_contents($root . '/index.php');

cd_assert(str_contains($js, "URLSearchParams"), 'coupon shim must read query parameters');
cd_assert(str_contains($js, "params.get('cupom')"), 'coupon shim must accept the canonical cupom parameter');
cd_assert(str_contains($js, "/^[A-Z0-9_-]{2,30}$/"), 'coupon deeplink must validate code shape before persisting');
cd_assert(str_contains($js, "localStorage.setItem(pendingKey, deeplinkCoupon)"), 'validated deeplink coupon must be stored as pending');
cd_assert(str_contains($js, "PRIMEIRA10") && str_contains($js, "PRIMEIRA15"), 'legacy coupon cleanup must remain intact');

foreach ([$product, $catalog, $home] as $page) {
    cd_assert(str_contains($page, "/js/first-purchase-popup-v1.js?v=<?= filemtime(__DIR__ . '/js/first-purchase-popup-v1.js')"), 'coupon shim must be cache-busted by filemtime');
}

fwrite(STDOUT, "coupon-deeplink: ok\n");
