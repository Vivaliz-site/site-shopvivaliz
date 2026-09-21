<?php
declare(strict_types=1);
$workflow=(string)file_get_contents(dirname(__DIR__).'/.github/workflows/desktop-commander-peer-repair.yml');
if(str_contains($workflow,'sep="\\\\n"')){
    fwrite(STDERR,"amazon support case_ids separator is literal backslash-n\n");
    exit(1);
}
if(!str_contains($workflow,'sep="\\n"')){
    fwrite(STDERR,"amazon support case_ids newline separator missing\n");
    exit(1);
}
echo "amazon-support-case-id-separator: OK\n";
