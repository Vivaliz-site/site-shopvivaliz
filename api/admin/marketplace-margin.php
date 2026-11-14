<?php
declare(strict_types=1);
/**
 * Ponte de sessão Admin para três APIs locais independentes.
 * Os segredos permanecem fora do Git/HTML e fora dos corpos de resposta.
 */
require_once __DIR__.'/../../includes/admin-guard.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function margin_reply(int $status,string $code):never {
 http_response_code($status);
 header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['ok'=>false,'error'=>$code],JSON_THROW_ON_ERROR);
 exit;
}
$channels=[
 'mercadolivre'=>['service'=>'mercadolivre-margin-auditor','port'=>8781],
 'shopee'=>['service'=>'shopee-margin-auditor','port'=>8782],
 'tiktok'=>['service'=>'tiktokshop-margin-auditor','port'=>8783],
];
$channel=(string)($_GET['channel']??'');
if(!isset($channels[$channel]))margin_reply(404,'unknown_channel');
$action=(string)($_GET['action']??'');
$routes=[
 'health'=>['GET','/v1/health'],
 'listings'=>['GET','/v1/listings?active=0'],
 'audits'=>['GET','/v1/audits'],
 'audit'=>['GET','/v1/audits/'],
 'export'=>['GET','/v1/audits/'],
 'start'=>['POST','/v1/audits'],
 'sync'=>['POST','/v1/sync'],
 'sync-status'=>['GET','/v1/sync/status'],
 'preview'=>['POST','/v1/preview'],
];
if(!isset($routes[$action]))margin_reply(404,'unknown_action');
[$requiredMethod,$path]=$routes[$action];
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if($method!==$requiredMethod)margin_reply(405,'method_not_allowed');
if($method==='POST'){
 $expected=(string)($_SESSION['sv_marketplace_margin_csrf']??'');
 $sent=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
 if(strlen($expected)!==64||!hash_equals($expected,$sent))margin_reply(403,'csrf_invalid');
}
$id=(string)($_GET['id']??'');
if(in_array($action,['audit','export'],true)){
 if(!preg_match('/^[a-f0-9-]{36}$/D',$id))margin_reply(400,'invalid_audit_id');
 $path.=$id;
}
$format=(string)($_GET['format']??'');
if($action==='export'){
 if(!in_array($format,['xlsx','pdf'],true))margin_reply(400,'invalid_format');
 $path.='/export?format='.$format;
}
$unit=$channels[$channel];
$tokenFile='/etc/shopvivaliz-margins/'.$unit['service'].'.env';
$token='';
if(is_readable($tokenFile)){
 $lines=@file($tokenFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
 foreach($lines as $line){
  if(preg_match('/^SV_MARGIN_API_TOKEN=([a-zA-Z0-9_-]{32,128})$/D',trim($line),$match)){
   $token=$match[1];break;
  }
 }
}
if($token==='')margin_reply(503,'margin_service_not_provisioned');
$body=null;
if($method==='POST'){
 $length=(int)($_SERVER['CONTENT_LENGTH']??0);
 if($length<0||$length>512*1024)margin_reply(413,'payload_too_large');
 $body=file_get_contents('php://input');
 if(!is_string($body)||strlen($body)>512*1024)margin_reply(400,'invalid_body');
 $value=json_decode($body,true);
 if(!is_array($value)||json_last_error()!==JSON_ERROR_NONE)margin_reply(400,'invalid_json');
}
$url='http://127.0.0.1:'.$unit['port'].$path;
$curl=curl_init($url);
if(!$curl)margin_reply(503,'margin_service_unavailable');
$options=[
 CURLOPT_RETURNTRANSFER=>true,
 CURLOPT_FOLLOWLOCATION=>false,
 CURLOPT_CONNECTTIMEOUT=>3,
 CURLOPT_TIMEOUT=>50,
 CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,
     'Accept: application/json','Content-Type: application/json'],
 CURLOPT_MAXREDIRS=>0,
];
if($method==='POST'){
 $options[CURLOPT_POST]=true;
 $options[CURLOPT_POSTFIELDS]=$body;
}
curl_setopt_array($curl,$options);
$output=curl_exec($curl);
$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
curl_close($curl);
if(!is_string($output)||$code<100||$code>599||strlen($output)>64*1024*1024){
 margin_reply(503,'margin_service_unavailable');
}
if($action==='export'&&$code===200){
 header('Content-Type: '.($format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
 header('Content-Disposition: attachment; filename="margens-'.$channel.'-'.$id.'.'.$format.'"');
 http_response_code(200);
 echo $output;
 exit;
}
header('Content-Type: application/json; charset=utf-8');
http_response_code($code);
echo $output;
