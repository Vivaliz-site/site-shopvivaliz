<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entry = (string)file_get_contents($root . '/api/olist/webhook.php');
$order = (string)file_get_contents($root . '/api/webhooks/order-status-update.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($entry, "SV_OLIST_INTERNAL_PAYLOAD"), 'entrypoint must forward parsed ERP payload');
$assert(str_contains($order, "SV_OLIST_INTERNAL_PAYLOAD"), 'order handler must reuse forwarded ERP payload');
$assert(str_contains($order, '$internalAuthenticated && is_array'), 'forwarded payload must only bypass body read after internal authentication');

echo "olist-order-webhook-forwarding: ok\n";
