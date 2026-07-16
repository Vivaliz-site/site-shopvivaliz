<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__,2).'/includes/order-idempotency.php';
$dir=svoi_dir();
$checks=[
 'storage_available'=>$dir!=='',
 'atomic_claim'=>function_exists('svoi_claim'),
 'cleanup_available'=>function_exists('svoi_cleanup'),
 'release_available'=>function_exists('svoi_release'),
];
$locks=0;
if($dir!==''){$locks=count(glob($dir.'/*.lock')?:[]);}
$ok=!in_array(false,$checks,true);
http_response_code($ok?200:503);
echo json_encode(['ok'=>$ok,'endpoint'=>'orders-idempotency','checks'=>$checks,'active_locks'=>$locks],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);