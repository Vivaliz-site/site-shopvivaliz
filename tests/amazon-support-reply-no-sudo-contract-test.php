<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$path=$root.'/.github/workflows/desktop-commander-peer-repair.yml';
$workflow=(string)file_get_contents($path);
$start=strpos($workflow,'- name: Reply to requested Amazon Seller Support cases');
$end=strpos($workflow,'- name: Publish sanitized Amazon support audit');
if($start===false||$end===false||$end<=$start){fwrite(STDERR,"amazon support reply block missing\n");exit(1);}
$block=substr($workflow,$start,$end-$start);
foreach(['sudo systemctl','sudo -u ','remote-debugging-port=9226'] as $forbidden){
    if(str_contains($block,$forbidden)){fwrite(STDERR,"reply block must not require sudo or isolated privileged browser: {$forbidden}\n");exit(1);}
}
foreach(['http://127.0.0.1:9225/json/version','for _ in $(seq 1 ','node /tmp/shopvivaliz-amazon-support-reply.mjs'] as $required){
    if(!str_contains($block,$required)){fwrite(STDERR,"reply block missing existing-CDP contract: {$required}\n");exit(1);}
}
echo "amazon-support-reply-no-sudo-contract: OK\n";
