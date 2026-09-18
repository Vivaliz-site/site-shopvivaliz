<?php
declare(strict_types=1);

function ardgAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}

$workflow=__DIR__.'/../.github/workflows/amazon-returns-safet-recovery.yml';
ardgAssert(is_file($workflow),'Amazon Returns SAFE-T recovery workflow must exist.');
$text=(string)file_get_contents($workflow);

$identityPos=strpos($text,'Verify local production identity');
$gatePos=strpos($text,'Reconcile SAFE-T release through canonical auto-deploy gate');
$verifyPos=strpos($text,'Recover Seller Central cycle and verify SAFE-T runtime');

ardgAssert($identityPos!==false && $gatePos!==false && $verifyPos!==false,'Recovery workflow must keep identity, deploy gate, and runtime verification steps.');
ardgAssert($identityPos<$gatePos && $gatePos<$verifyPos,'Canonical deploy reconciliation must run before SAFE-T runtime verification.');
ardgAssert(str_contains($text,'test "$(hostname)" = \'shopvivaliz-free-a1\''),'Recovery must fail closed on the wrong host.');
ardgAssert(str_contains($text,'sudo -n true'),'Recovery must require non-interactive administrative authorization.');
ardgAssert(str_contains($text,'repo=/home/ubuntu/amazon-returns-deploy-source'),'Recovery must use the isolated Amazon Returns deploy checkout.');
ardgAssert(str_contains($text,'deploy=/home/ubuntu/amazon-returns-deploy'),'Recovery must use the immutable Amazon Returns deploy root.');
ardgAssert(str_contains($text,'git -C "$repo" fetch --quiet origin main'),'Recovery must refresh origin/main before deciding whether to deploy.');
ardgAssert(str_contains($text,'sudo -n systemctl start amazon-returns-deploy.service'),'Recovery must trigger the canonical root auto-deploy service rather than mutate current directly.');
ardgAssert(str_contains($text,'deploy_reconciled_sha=%s'),'Recovery must emit exact-SHA deployment evidence.');
ardgAssert(str_contains($text,'test "$origin_sha" = "$current_sha"'),'Runtime verification must still require exact origin/current SHA equality.');
ardgAssert(!str_contains($text,'ln -sfn') && !str_contains($text,'rm -rf /home/ubuntu/amazon-returns-deploy/current'),'Recovery workflow must never rewrite current directly.');
ardgAssert(!str_contains($text,'deploy-production.sh'),'Recovery workflow must not bypass the canonical amazon-returns-deploy.service gate.');
ardgAssert(!str_contains($text,'|| true'),'Recovery workflow must not suppress operational failures with unconditional true fallbacks.');

echo "amazon-returns-recovery-deploy-gate-test: OK\n";
