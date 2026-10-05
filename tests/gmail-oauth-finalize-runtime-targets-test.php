<?php
declare(strict_types=1);

$workflow=(string)file_get_contents(dirname(__DIR__).'/.github/workflows/gmail-finalize-oauth.yml');

$checks=[
    'site env target'=>str_contains($workflow, '/home/ubuntu/shopvivaliz-deploy/shared/.env'),
    'canonical SAFE-T env target'=>str_contains($workflow, '/home/ubuntu/amazon-returns-deploy/shared/.env'),
    'canonical SAFE-T service restart'=>str_contains($workflow, 'amazon-returns-safet.service'),
    'legacy/site service not restarted'=>!str_contains($workflow, 'systemctl restart shopvivaliz-amazon-returns.service'),
    'preserve original mode'=>str_contains($workflow, 'stat.S_IMODE'),
    'preserve original owner'=>str_contains($workflow, 'os.chown'),
    'workflow change does not replay stale OAuth job'=>!str_contains($workflow, '- ".github/workflows/gmail-finalize-oauth.yml"'),
];

foreach($checks as $name=>$ok){
    if(!$ok){
        fwrite(STDERR,"FAIL: {$name}\n");
        exit(1);
    }
}

echo "PASS gmail-oauth-finalize-runtime-targets\n";
