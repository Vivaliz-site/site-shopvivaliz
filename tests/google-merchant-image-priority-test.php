<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/google-merchant-image-priority.php';

function check(bool $cond, string $message): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$tests = [
    ['JVAQMA56', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/aa81acb4cedba991a908d08bf73cdee2.jpeg', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/0ad0bd42591c8d1353ae0b7ec30332ed.jpg'],
    ['JVAQAC55', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/18525e83101275f861ee7b80896247a0.jpeg', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/e672f12f752df17d50ba5d2ad12ad3dc.jpg'],
    ['7', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/f10d7a6d63f81fd9f25de5c83904ebf4.jpeg', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/7b77655b0c4ec4a8b2ce0ff867893ed1.jpg'],
    ['JVTBPA32', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/2b65263add743d63a2b19a5f41d8a603.jpeg', 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/001e5feb2183c8a14eff8272ce4f5789.jpeg'],
];
foreach ($tests as [$id, $current, $preferred]) {
    $other = 'https://s3.amazonaws.com/tiny-anexos-us/erp/example.jpg';
    $result = svgm_promote_verified_erp_image($id, $current, [$other, $preferred]);
    check($result['primary'] === $preferred, "$id selected original ERP high-resolution alternative");
    check($result['additional'] === [$other, $current], "$id retains source and avoids duplicate");
    $missing = svgm_promote_verified_erp_image($id, $current, [$other]);
    check($missing['primary'] === $current, "$id fails closed when ERP alternative disappears");
    $changed = svgm_promote_verified_erp_image($id, $other, [$preferred]);
    check($changed['primary'] === $other, "$id fails closed after primary changed");
}
$unknown = svgm_promote_verified_erp_image('UNRELATED', $tests[0][1], [$tests[0][2]]);
check($unknown['primary'] === $tests[0][1], 'unknown SKU unchanged');
check(count($unknown['additional']) === 1, 'unknown SKU additional list unchanged');
echo "PASS: 4 verified Merchant priorities and 10 fail-closed checks\n";
