<?php
declare(strict_types=1);

function svorc_set(array $body, array $resolvedItems): void {
    $GLOBALS['shopvivaliz_order_body'] = $body;
    $GLOBALS['shopvivaliz_order_items'] = $resolvedItems;
}

function svorc_body(): array {
    $body = $GLOBALS['shopvivaliz_order_body'] ?? [];
    return is_array($body) ? $body : [];
}

function svorc_items(): array {
    $items = $GLOBALS['shopvivaliz_order_items'] ?? [];
    return is_array($items) ? $items : [];
}
