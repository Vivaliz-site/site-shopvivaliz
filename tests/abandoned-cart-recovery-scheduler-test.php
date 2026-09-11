<?php
declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$assert=static function(bool $ok,string $msg)use(&$errors):void{if(!$ok)$errors[]=$msg;};
$service=$root.'/deploy/systemd/shopvivaliz-abandoned-cart-recovery.service';
$timer=$root.'/deploy/systemd/shopvivaliz-abandoned-cart-recovery.timer';
$installer=$root.'/scripts/install-abandoned-cart-recovery-service.sh';
$deploy=(string)file_get_contents($root.'/scripts/deploy-production.sh');
$master=(string)file_get_contents($root.'/.github/workflows/master-production-pipeline.yml');
$assert(is_file($service),'recovery service unit must exist');
$assert(is_file($timer),'recovery timer unit must exist');
$assert(is_file($installer),'shared recovery installer must exist');
if(is_file($service)){$s=(string)file_get_contents($service);
 $assert(str_contains($s,'scripts/send-abandoned-cart-emails.php'),'service must execute recovery sender');
 $assert(str_contains($s,'/home/ubuntu/shopvivaliz-deploy/shared/locks/abandoned-cart-recovery.lock'),'service must serialize sends with shared lock');
 $assert(str_contains($s,'User=ubuntu'),'service must run unprivileged');
}
if(is_file($timer)){$t=(string)file_get_contents($timer);
 $assert(str_contains($t,'OnUnitActiveSec=30min'),'timer must run every 30 minutes');
 $assert(str_contains($t,'Persistent=true'),'timer must catch up after downtime');
}
$assert(str_contains($deploy,'install-abandoned-cart-recovery-service.sh'),'canonical runner must use shared recovery installer');
$assert(str_contains($master,'install-abandoned-cart-recovery-service.sh'),'master production pipeline must use shared recovery installer');
$assert(str_contains($master,'shopvivaliz-abandoned-cart-recovery.timer'),'master production pipeline must verify recovery timer');
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);} echo "abandoned-cart-recovery-scheduler: ok\n";
