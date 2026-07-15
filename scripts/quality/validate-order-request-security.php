<?php
declare(strict_types=1);
$required=['includes/order-request-context.php','includes/order-idempotency.php','includes/order-rate-limit.php','api/orders/security-health.php'];$errors=[];foreach($required as $file){$path=__DIR__.'/../../'.$file;if(!is_file($path))$errors[]="missing: $file";elseif(filesize($path)===0)$errors[]="empty: $file";}
$validator=(string)@file_get_contents(__DIR__.'/../../api/orders/create-validated.php');foreach(['svorl_allow','svoi_key','svoi_claim','svorc_set','duplicate_order_request','rate_limit_exceeded'] as $token){if(!str_contains($validator,$token))$errors[]="validator missing: $token";}
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Order request security validation passed.\n";