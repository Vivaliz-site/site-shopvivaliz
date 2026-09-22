<?php
declare(strict_types=1);
$workflow=(string)file_get_contents(dirname(__DIR__).'/.github/workflows/desktop-commander-peer-repair.yml');
$start=strpos($workflow,'- name: Reply to requested Amazon Seller Support cases');
$end=strpos($workflow,'- name: Publish sanitized Amazon support audit');
if($start===false||$end===false||$end<=$start){fwrite(STDERR,"Amazon reply block missing\n");exit(1);}
$block=substr($workflow,$start,$end-$start);
foreach([
  'shopvivaliz-amazon-support-profile',
  'SELLER_CENTRAL_PROFILE',
  'remote-debugging-port=9226',
  'rm -rf "$support_profile"',
  'SingletonLock',
  'SELLER_CENTRAL_AUTH=AUTHENTICATED',
  'AVAILABLE_ACTIONS'
] as $required){
  if(!str_contains($block,$required)){
    fwrite(STDERR,"missing ephemeral-profile contract marker: {$required}\n");
    exit(1);
  }
}
if(str_contains($block,'--user-data-dir=$SELLER_CENTRAL_PROFILE')){
  fwrite(STDERR,"support reply must not launch Chromium against the live Seller Central profile\n");
  exit(1);
}
echo "amazon-support-ephemeral-profile-contract: OK\n";
