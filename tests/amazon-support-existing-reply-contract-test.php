<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/shopvivaliz-remote-access.yml');
$scriptPath=$root.'/scripts/respond-existing-amazon-support-cases.mjs';

if(!str_contains($workflow,'amazon_support_reply_existing')) {
    fwrite(STDERR,"missing allowlisted amazon_support_reply_existing action\n");
    exit(1);
}
if(!is_file($scriptPath)) {
    fwrite(STDERR,"missing responder script\n");
    exit(1);
}
$script=(string)file_get_contents($scriptPath);
foreach(['22153077391','22153259501','SUPPORT_REPLY_CONFIRMED'] as $needle){
    if(!str_contains($script,$needle)){
        fwrite(STDERR,"missing contract marker: {$needle}\n");
        exit(1);
    }
}
foreach(['create-case','open-case','supportOpen(','SELLER_SUPPORT_OPEN'] as $forbidden){
    if(str_contains($script,$forbidden)){
        fwrite(STDERR,"responder must never create/open a new case: {$forbidden}\n");
        exit(1);
    }
}
echo "amazon-support-existing-reply-contract-test: OK\n";
