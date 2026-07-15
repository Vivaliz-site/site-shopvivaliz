<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$required=['api/orders/create.php','api/orders/create-validated.php','api/orders/process-validated.php','api/orders/context-health.php','includes/order-request-context.php'];
$errors=[];
foreach($required as $file){$path=$root.'/'.$file;if(!is_file($path))$errors[]="missing: $file";elseif(filesize($path)===0)$errors[]="empty: $file";}
$create=(string)@file_get_contents($root.'/api/orders/create.php');
$validated=(string)@file_get_contents($root.'/api/orders/create-validated.php');
$processor=(string)@file_get_contents($root.'/api/orders/process-validated.php');
if(!str_contains($create,"create-validated.php"))$errors[]='create.php is not a validated entrypoint';
if(substr_count($validated,'php://input')!==1)$errors[]='validated endpoint must read php://input exactly once';
if(!str_contains($validated,'process-validated.php'))$errors[]='validated processor not wired';
if(str_contains($processor,'php://input'))$errors[]='processor must not read request body';
foreach(['svorc_body','svorc_items','validated_context_missing'] as $token){if(!str_contains($processor,$token))$errors[]="processor missing: $token";}
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Order context validation passed.\n";