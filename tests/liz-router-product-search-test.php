<?php
declare(strict_types=1);

function svlr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$routerSource = (string)file_get_contents(__DIR__ . '/../api/liz-router.php');
$searchSource = (string)file_get_contents(__DIR__ . '/../api/product-search.php');

svlr_assert($routerSource !== '', 'liz-router source should be readable.');
svlr_assert($searchSource !== '', 'product-search source should be readable.');

svlr_assert(
    str_contains($routerSource, "require_once dirname(__DIR__) . '/includes/secure-session.php';"),
    'liz-router must bootstrap secure sessions before using rate limiting.'
);
svlr_assert(
    str_contains($routerSource, "require_once dirname(__DIR__) . '/includes/rate-limiter.php';"),
    'liz-router must use the shared rate limiter instead of ad-hoc session state.'
);
svlr_assert(
    str_contains($routerSource, 'function lzr_authorize_search(): void'),
    'liz-router must validate agent authorization before proxying product search.'
);
svlr_assert(
    str_contains($routerSource, "RateLimiter::isAllowed('liz-search:' . \$ip, 10, 60)"),
    'liz-router product search must keep a bounded shared rate limit.'
);
svlr_assert(
    str_contains($routerSource, "'&page=' . \$page"),
    'liz-router must forward pagination to the product search endpoint.'
);
svlr_assert(
    str_contains($searchSource, 'function svcat_root(): string { return dirname(__DIR__); }'),
    'product-search root helper must stay inside the repository root.'
);
svlr_assert(
    str_contains($searchSource, "require_once svcat_root() . '/includes/catalog-runtime.php';"),
    'product-search must read catalog-runtime from the repository root.'
);

fwrite(STDOUT, "PASS: liz router product search safeguards.\n");
