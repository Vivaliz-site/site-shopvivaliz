<?php
$root = dirname(__DIR__);
$names = [
 'desktop-commander-vm2-reauth.yml','emergency-vm-reboot.yml','emergency-web-recovery.yml',
 'fredwin-2222-secure-recovery.yml','grant-mei-mail-read.yml','mei-email-autorepair-15min-deploy.yml',
 'mei-email-emergency-hardening.yml','mei-email-ndr-guard-deploy.yml',
 'mei-email-terminate-stale-pg-backend-v1.yml','mei-email-v040-direct-restart.yml',
 'vm-desktop-commander-authorize.yml','vm-desktop-commander-read-auth.yml'
];
$bad=[];
foreach($names as $name){ if(is_file($root.'/.github/workflows/'.$name)) $bad[]=$name; }
if($bad){ fwrite(STDERR,"High-risk recovery workflows must remain disabled:\n".implode("\n",$bad)."\n"); exit(1); }
echo "high-risk-recovery-workflows-disabled-contract: ok\n";
