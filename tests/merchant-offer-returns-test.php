<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$errors = [];
$assert = static function (bool $ok, string $message) use (&$errors): void { if (!$ok) $errors[] = $message; };
foreach (['google-merchant-feed.php','google-shopping-feed.php'] as $file) {
    $src = (string)file_get_contents($root . '/' . $file);
    $assert(substr_count($src, '<g:returns>') === 1, "$file must publish one returns block per item template");
    $assert(str_contains($src, '<g:country>BR</g:country>'), "$file must target BR returns");
    $assert(str_contains($src, '<g:window_days>7</g:window_days>'), "$file must publish seven-day return window");
    $assert(str_contains($src, '<g:window_type>FINITE_RETURN_WINDOW</g:window_type>'), "$file must use finite return window");
    $assert(str_contains($src, '<g:item_condition>NEW</g:item_condition>'), "$file must accept new condition");
    $assert(str_contains($src, '<g:item_condition>LIKE_NEW</g:item_condition>'), "$file must cover opened/like-new returns");
    $assert(str_contains($src, '<g:method>BY_MAIL</g:method>'), "$file must publish return by mail");
    $assert(str_contains($src, '<g:outcome>REFUND</g:outcome>'), "$file must publish refund outcome");
    $assert(!str_contains($src, '<g:shipping_fee>'), "$file must omit return shipping fee so Merchant defaults it to zero");
    $assert(!str_contains($src, '<g:shipping_fee_type>'), "$file must not label free returns as customer-paid");
    $assert(str_contains($src, '<g:policy_url>https://shopvivaliz.com.br/politica-devolucoes/</g:policy_url>'), "$file must link public policy");
}

// Regression for Google's 2026-10 Merchant listings warning. A 7-day
// withdrawal is free to return, but checkout delivery charges are unrelated.
$product = (string)file_get_contents($root . '/produto.php');
$policy = (string)file_get_contents($root . '/politica-devolucoes.php');
$found = preg_match("/'hasMerchantReturnPolicy'\s*=>\s*\[([^\]]*)\]/s", $product, $m) === 1;
$assert($found, 'Product Offer must publish a merchant return policy');
if ($found) {
    $block = $m[1];
    foreach ([
        "'applicableCountry'    => 'BR'",
        "'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow'",
        "'merchantReturnDays'   => 7",
        "'returnMethod'         => 'https://schema.org/ReturnByMail'",
        "'returnFees'           => 'https://schema.org/FreeReturn'",
    ] as $field) {
        $assert(str_contains($block, $field), 'Product Offer return schema missing: ' . $field);
    }
    $assert(!str_contains($block, 'returnShippingFeesAmount'),
        'Free returns must not claim a nonzero shipping fee');
}
$assert(str_contains($policy, '7 dias corridos'),
    'Public return policy must define the legal window');
$assert(str_contains($policy, 'o consumidor não paga o frete de devolução'),
    'Free return schema must match the published withdrawal policy');

if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL); exit(1); }
echo "merchant-offer-returns: ok\n";
