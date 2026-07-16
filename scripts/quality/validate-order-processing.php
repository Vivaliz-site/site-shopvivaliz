<?php
declare(strict_types=1);
$required=['includes/order-idempotency.php','includes/order-rate-limit.php','js/checkout-idempotency-v122.js','api/orders/idempotency-health.php'];$errors=[];foreach($required as $file){$path=__DIR__.'/../../'.$file;if(!is_file($path))$errors[]="missing: $file";elseif(filesize($path)===0)$errors[]="empty: $file";}
$idempotency=(string)@file_get_contents(__DIR__.'/../../includes/order-idempotency.php');foreach(['fopen($path, \'x\')','svoi_cleanup','svoi_release'] as $token){if(!str_contains($idempotency,$token))$errors[]="idempotency missing: $token";}
$rate=(string)@file_get_contents(__DIR__.'/../../includes/order-rate-limit.php');foreach(['SHOPVIVALIZ_TRUST_PROXY','FILTER_VALIDATE_IP'] as $token){if(!str_contains($rate,$token))$errors[]="rate limit missing: $token";}
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Order processing validation passed.\n";