<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=$root.'/scripts/amazon-safet-readback-oci-site.sh';
if(!is_file($script)){fwrite(STDERR,"amazon SAFE-T readback script missing\n");exit(1);}
$text=(string)file_get_contents($script);
$required=[
  'SAFE_T_READBACK_BEGIN',
  'SELLER_CENTRAL_AUTH=AUTHENTICATED',
  'seller-central-support-lookup-probe.mjs',
  'https://sellercentral.amazon.com.br/safet-claims/claim/',
  '52214-19729-8255607',
  '701-4306982-6000233',
  'safe-t-status-parser.mjs',
  'claim_status',
  'appeal_submitted',
  'appeal_deadline_at',
  'SAFE_T_READBACK_VERIFIED=true',
];
foreach($required as $needle){if(strpos($text,$needle)===false){fwrite(STDERR,"SAFE-T readback missing contract: {$needle}\n");exit(1);}}
$forbidden=[
  'SAFE_T_SUBMIT',
  'SAFE_T_APPEAL',
  'SELLER_SUPPORT_OPEN',
  'SELLER_SUPPORT_UPDATE',
  'fetch(',
  'Input.insertText',
  'Input.dispatchMouseEvent',
  '. /home/ubuntu/amazon-returns-deploy/shared/.env',
];
foreach($forbidden as $needle){if(strpos($text,$needle)!==false){fwrite(STDERR,"SAFE-T readback contains forbidden write path: {$needle}\n");exit(1);}}

$workflow=$root.'/.github/workflows/shopvivaliz-remote-access.yml';
$wf=(string)file_get_contents($workflow);
foreach([
  'amazon_safet_readback_52214',
  'sudo -n bash scripts/amazon-safet-readback-oci-site.sh',
  'action.startswith("amazon_")',
] as $needle){if(strpos($wf,$needle)===false){fwrite(STDERR,"remote SAFE-T readback control missing: {$needle}\n");exit(1);}}

echo "amazon-safet-readback-contract: ok\n";
