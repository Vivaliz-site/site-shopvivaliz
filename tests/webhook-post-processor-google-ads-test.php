<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/api/webhook-post-processor.php');
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }
check(str_contains($source, "require_once __DIR__ . '/../includes/google-ads-order-conversion.php';"), 'post processor must load Google Ads approved-order conversion helper');
check(str_contains($source, "svgads_send_approved_purchase(\$orderData)"), 'approved payment must trigger direct Google Ads conversion upload');
check(str_contains($source, "google_ads_purchase_sent"), 'order must persist Google Ads conversion idempotency state');
check(str_contains($source, "google_ads_purchase_sent_at"), 'order must persist Google Ads conversion timestamp');
echo "PASS: webhook post processor persists direct Google Ads purchase delivery.\n";
