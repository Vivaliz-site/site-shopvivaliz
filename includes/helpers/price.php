<?php

function shopvivaliz_format_price($price) {
    if ($price === null || $price === '' || floatval($price) <= 0) {
        return null; // remove fallback completamente
    }

    return 'R$ ' . number_format((float)$price, 2, ',', '.');
}
