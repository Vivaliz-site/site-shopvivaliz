<?php
declare(strict_types=1);
$wf=(string)file_get_contents(dirname(__DIR__).'/.github/workflows/desktop-commander-peer-repair.yml');
foreach(['Reopen case','Reabrir caso','TERMINAL_NO_REOPEN_ACTION','READ_BACK_FAILED'] as $required){
    if(!str_contains($wf,$required)){fwrite(STDERR,"missing existing-case reopen contract: {$required}\n");exit(1);}
}
foreach(['open new case','create new case','SELLER_SUPPORT_OPEN'] as $forbidden){
    if(str_contains(strtolower($wf),strtolower($forbidden))){fwrite(STDERR,"workflow must not create a new support case\n");exit(1);}
}
echo "amazon-support-reopen-existing-contract: OK\n";
