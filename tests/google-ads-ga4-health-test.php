<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/includes/integration-health.php';
$errors=[];
$assert=static function(bool $ok,string $msg)use(&$errors):void{if(!$ok)$errors[]=$msg;};
$assert(function_exists('svih_google_ads_ga4_purchase_configured'),'GA4 health parser helper must exist');
if(function_exists('svih_google_ads_ga4_purchase_configured')){
 $good=['results'=>[['conversionAction'=>['status'=>'ENABLED','type'=>'GOOGLE_ANALYTICS_4_PURCHASE','primaryForGoal'=>true,'googleAnalytics4Settings'=>['eventName'=>'purchase','propertyId'=>'123']]]]];
 $assert(svih_google_ads_ga4_purchase_configured($good),'verified GA4 purchase import must be accepted');
 foreach(['status','type','primary','event','property'] as $bad){$x=$good;
  if($bad==='status')$x['results'][0]['conversionAction']['status']='REMOVED';
  if($bad==='type')$x['results'][0]['conversionAction']['type']='WEBPAGE';
  if($bad==='primary')$x['results'][0]['conversionAction']['primaryForGoal']=false;
  if($bad==='event')$x['results'][0]['conversionAction']['googleAnalytics4Settings']['eventName']='add_to_cart';
  if($bad==='property')$x['results'][0]['conversionAction']['googleAnalytics4Settings']['propertyId']='';
  $assert(!svih_google_ads_ga4_purchase_configured($x),'invalid GA4 purchase evidence accepted: '.$bad);
 }
}
$health=(string)file_get_contents($root.'/includes/integration-health.php');
$assert(str_contains($health,"GOOGLE_ADS_CONVERSION_SOURCE"),'health must read conversion source');
$assert(str_contains($health,"GOOGLE_ADS_GA4_IMPORT_VERIFIED"),'health must require explicit prior linkage verification');
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);} echo "google-ads-ga4-health: ok\n";
