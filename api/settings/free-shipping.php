<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

require_once dirname(__DIR__, 2) . '/includes/site-settings.php';

echo json_encode(['ok' => true] + sv_free_shipping_config(), JSON_UNESCAPED_UNICODE);
