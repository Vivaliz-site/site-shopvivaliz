<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$errors=[];
$configPath=$root.'/config/official-site.php';
$apiPath=$root.'/api/site/official-reference.php';
$docPath=$root.'/docs/knowledge/official-site.md';
foreach([$configPath,$apiPath,$docPath] as $path){if(!is_file($path))$errors[]='missing: '.str_replace($root.'/','',$path);elseif(filesize($path)===0)$errors[]='empty: '.str_replace($root.'/','',$path);}
$config=is_file($configPath)?require $configPath:[];
if(!is_array($config))$errors[]='official site config invalid';
if(($config['base_url']??'')!=='https://shopvivaliz.com.br')$errors[]='official base_url mismatch';
foreach(['terms','about','help','contact'] as $key){if(trim((string)($config['navigation'][$key]??''))==='')$errors[]="missing navigation: $key";}
if(count($config['payment_methods']??[])<5)$errors[]='payment methods incomplete';
if(count($config['top_categories']??[])<6)$errors[]='top categories incomplete';
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Official site reference validation passed.\n";