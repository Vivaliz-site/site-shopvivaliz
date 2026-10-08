<?php
declare(strict_types=1);

$workflow=__DIR__.'/../.github/workflows/production-runner-out-of-band-recovery.yml';
if(!is_file($workflow)){
    fwrite(STDERR,"production runner out-of-band recovery workflow is missing\n");
    exit(1);
}
$src=(string)file_get_contents($workflow);
$checks=[
    'runs-on: ubuntu-latest'=>'recovery must not depend on the broken production runner',
    'environment: Production'=>'recovery must use the protected production secret boundary',
    'SHOPVIVALIZ_VM_HOST'=>'recovery must enter through the provisioned backend SSH route',
    'ubuntu@10.0.1.112'=>'recovery must hop privately from backend to production A1',
    'systemctl --user restart shopvivaliz-actions-runner.service'=>'recovery must restart only the canonical user runner service',
    'RUNNER_SERVICE='=>'recovery must emit sanitized service evidence',
    'RUNNER_LISTENER_COUNT='=>'recovery must verify exactly one listener',
];
foreach($checks as $needle=>$message){
    if(!str_contains($src,$needle)){
        fwrite(STDERR,$message.": ".$needle."\n");
        exit(1);
    }
}
foreach([
    'registration-token',
    'config.sh --unattended',
    'actions/runners/',
    'StrictHostKeyChecking=no',
] as $forbidden){
    if(str_contains($src,$forbidden)){
        fwrite(STDERR,"out-of-band recovery must not re-register or weaken SSH trust: ".$forbidden."\n");
        exit(1);
    }
}
echo "production-runner-out-of-band-recovery-contract: OK\n";
