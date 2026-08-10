<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../includes/active-coupons.php';

echo json_encode(sv_active_coupons(6), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
