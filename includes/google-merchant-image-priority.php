<?php
declare(strict_types=1);

/**
 * Merchant-only, ERP-origin image priority corrections verified on 2026-11-07.
 *
 * The live production feed identified four primary images whose shortest side
 * is <500px. The preferred candidate for each SKU is an existing Olist/Tiny
 * additional image of the SAME feed item, measured at >=1200x1200.
 *
 * Fail closed: only promote when the exact previously measured primary image
 * is still present AND the verified replacement is still an ERP additional
 * image for that same item. No remote image fetches per feed request, no
 * third-party substitutions, no ERP mutations or storefront image changes.
 *
 * @param list<string> $additional
 * @return array{primary:string,additional:list<string>}
 */
function svgm_promote_verified_erp_image(string $merchantId, string $primary, array $additional): array
{
    static $rules = [
        'JVAQMA56' => ['current' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/aa81acb4cedba991a908d08bf73cdee2.jpeg', 'preferred' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/0ad0bd42591c8d1353ae0b7ec30332ed.jpg'], // 326x500 -> 1200x1200
        'JVAQAC55' => ['current' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/18525e83101275f861ee7b80896247a0.jpeg', 'preferred' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/e672f12f752df17d50ba5d2ad12ad3dc.jpg'], // 500x483 -> 1200x1200
        '7' => ['current' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/f10d7a6d63f81fd9f25de5c83904ebf4.jpeg', 'preferred' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/7b77655b0c4ec4a8b2ce0ff867893ed1.jpg'], // 500x494 -> 1254x1254
        'JVTBPA32' => ['current' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/2b65263add743d63a2b19a5f41d8a603.jpeg', 'preferred' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/001e5feb2183c8a14eff8272ce4f5789.jpeg'], // 500x368 -> 1254x1254
    ];
    $additional = array_values(array_unique(array_filter(
        $additional,
        static fn(mixed $url): bool => is_string($url) && trim($url) !== ''
    )));
    $rule = $rules[$merchantId] ?? null;
    if ($rule === null || $primary !== $rule['current'] ||
        !in_array($rule['preferred'], $additional, true)) {
        return ['primary' => $primary, 'additional' => $additional];
    }
    $next = array_values(array_filter(
        $additional,
        static fn(string $url): bool => $url !== $rule['preferred']
    ));
    if (!in_array($primary, $next, true)) $next[] = $primary;
    return ['primary' => $rule['preferred'], 'additional' => $next];
}
