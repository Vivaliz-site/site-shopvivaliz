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
    'workflow checks whether OAuth request changed'=>str_contains($workflow, "git diff --name-only \"\\$BEFORE\" \"\\$GITHUB_SHA\" -- ops/gmail-oauth-finalize.json"),
    'token install gated by real OAuth request'=>str_contains($workflow, "if: steps.request_change.outputs.changed == 'true'"),
];

foreach($checks as $name=>$ok){
    if(!$ok){
        fwrite(STDERR,"FAIL: {$name}\n");
        exit(1);
    }
}

echo "PASS gmail-oauth-finalize-runtime-targets\n";
