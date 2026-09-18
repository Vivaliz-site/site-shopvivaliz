<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/includes/popup-cupons.php');
$required = [
    "fetch('/api/coupons/active.php',{cache:'no-store',keepalive:true})",
    "window.addEventListener('pagehide'",
    "window.addEventListener('pageshow'",
    "sv_popup_cupons_page_exiting||document.visibilityState==='hidden'",
    "console.error('[popup-cupons] load error:',err)",
];
foreach ($required as $marker) {
    if (!str_contains($source, $marker)) {
        fwrite(STDERR, "popup coupon navigation regression missing: {$marker}\n");
        exit(1);
    }
}
echo "popup_coupon_navigation_abort_regression=PASS\n";
