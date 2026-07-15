<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__,2).'/includes/product-price-enrich.php';
require_once dirname(__DIR__,2).'/includes/catalog-runtime.php';
svp_env_load();
$secret='';foreach(['QUOTE_SIGNING_KEY','APP_KEY','SHOPVIVALIZ_APP_KEY','SHOPVIVALIZ_AGENT_KEY'] as $key){$value=getenv($key);if(is_string($value)&&trim($value)!==''){$secret=trim($value);break;}}
$catalog=svcr_products();
$storage=dirname(__DIR__,2).'/storage/orders';
$checks=['catalog_ready'=>count($catalog)>0,'order_storage_ready'=>(is_dir($storage)&&is_writable($storage))||is_writable(sys_get_temp_dir()),'quote_signing_configured'=>$secret!=='','authoritative_resolver'=>function_exists('svp_enrich_products')];
$ok=!in_array(false,$checks,true);
http_response_code($ok?200:503);
echo json_encode(['ok'=>$ok,'endpoint'=>'orders','checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
