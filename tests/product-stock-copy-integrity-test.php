<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/product-review-integrity.php';

$singular = '<div class="urgency-tag"><i>🔥</i> Apenas 1 unidades restantes!</div>';
$plural = '<div class="urgency-tag"><i>🔥</i> Apenas 2 unidades restantes!</div>';

$singularFiltered = sv_product_review_integrity_filter($singular);
$pluralFiltered = sv_product_review_integrity_filter($plural);

if (!str_contains($singularFiltered, 'Apenas 1 unidade restante!')) {
    fwrite(STDERR, "FAIL: singular stock copy was not normalized\n");
    exit(1);
}

if (str_contains($singularFiltered, '1 unidades restantes')) {
    fwrite(STDERR, "FAIL: invalid singular stock copy remains\n");
    exit(1);
}

if ($pluralFiltered !== $plural) {
    fwrite(STDERR, "FAIL: plural stock copy was changed unexpectedly\n");
    exit(1);
}

echo "product-stock-copy-integrity: ok\n";
