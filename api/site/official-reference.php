<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
$config=require dirname(__DIR__,2).'/config/official-site.php';
echo json_encode(['ok'=>true,'endpoint'=>'official-site-reference','data'=>$config],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);