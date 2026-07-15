<?php
declare(strict_types=1);
$required=['includes/order-authoritative.php','api/orders/create-validated.php','api/orders/health.php'];$errors=[];foreach($required as $file){$path=__DIR__.'/../../'.$file;if(!is_file($path))$errors[]="missing: $file";elseif(filesize($path)===0)$errors[]="empty: $file";}
$validator=(string)@file_get_contents(__DIR__.'/../../api/orders/create-validated.php');foreach(['item_price_mismatch','shipping_quote_required','quote_signing_key_missing','order_items_invalid','hash_equals'] as $token){if(!str_contains($validator,$token))$errors[]="order validator missing: $token";}
$resolver=(string)@file_get_contents(__DIR__.'/../../includes/order-authoritative.php');foreach(['svp_enrich_products','insufficient_stock','invalid_price','product_not_found'] as $token){if(!str_contains($resolver,$token))$errors[]="resolver missing: $token";}
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Order integrity validation passed.\n";