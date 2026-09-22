<?php
// Authorized-actor CI retrigger; no runtime behavior.
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=$root.'/.github/workflows/amazon-support-reply-oci-breakglass.yml';
if(!is_file($workflow)){fwrite(STDERR,"amazon support Bastion breakglass workflow missing\n");exit(1);}
$text=(string)file_get_contents($workflow);
$required=[
  'issue_comment:',
  'types: [created]',
  'runs-on: ubuntu-latest',
  'environment: Production',
  "github.event.issue.number == 1586",
  "github.event.comment.user.login == 'fredmourao-ai'",
  "github.event.comment.body == '/amazon-support-reply case_ids=22153077391,22153259501'",
  'OCI_CLI_USER',
  'OCI_CLI_TENANCY',
  'OCI_CLI_FINGERPRINT',
  'OCI_CLI_REGION',
  'OCI_CLI_KEY_CONTENT',
  'SHOPVIVALIZ_VM_SSH_KEY',
  'SITE_PRIVATE_IP: 10.0.1.112',
  'bastion session create-port-forwarding',
  'ssh-metadata',
  'BASTION_SSH_COMMAND',
  "ssh-keygen -q -t rsa -b 4096",
  'HostKeyAlgorithms=+ssh-rsa',
  'PubkeyAcceptedAlgorithms=+ssh-rsa',
  '--session-ttl 1800',
  'shopvivaliz-site-amazon-support',
  'HostKeyAlias=127.0.0.1',
  'sudo -n bash -s',
  'scripts/amazon-support-reply-oci-site.sh',
  '22153077391',
  '22153259501',
  'read_back',
  'ALREADY_EXISTS',
  'SENT',
  'AMAZON_SUPPORT_REPLY_VERIFIED=true',
];
foreach($required as $needle){if(strpos($text,$needle)===false){fwrite(STDERR,"amazon support Bastion breakglass missing contract: {$needle}\n");exit(1);}}
$forbidden=[
  'runs-on: self-hosted',
  '--session-ttl 900',
  "ssh-keygen -q -t ed25519",
  'ComputeInstanceAgentClient',
  'create_instance_agent_command',
  'base64.b64encode',
  'chunk_size = 2500',
  'append-amazon-support-script',
  'compute instance action',
  'SELLER_SUPPORT_OPEN',
  'create new case',
  'open new case',
  '. /home/ubuntu/amazon-returns-deploy/shared/.env',
  'tail -20 /tmp/shopvivaliz-support-probe.out',
];
foreach($forbidden as $needle){if(strpos($text,$needle)!==false){fwrite(STDERR,"amazon support Bastion breakglass contains forbidden pattern: {$needle}\n");exit(1);}}

$script=$root.'/scripts/amazon-support-reply-oci-site.sh';
if(!is_file($script)){fwrite(STDERR,"amazon support Bastion site script missing\n");exit(1);}
$scriptText=(string)file_get_contents($script);
$scriptRequired=[
  'SELLER_CENTRAL_SERVICE_BUSY',
  'seller-central-support-lookup-probe.mjs',
  'SUPPORT_LOOKUP_PROBE=PASS',
  'SUPPORT_PROBE_RESULT=',
  'SUPPORT_PROBE_OUTPUT_SHA256=',
  'PROBE_NO_JSON_OUTPUT',
  'SUPPORT_AUTH_CHECK_FAILED',
  'viewCaseMetaData?.canEditCase===true',
  'TERMINAL_NOT_EDITABLE',
  'SearchForCases',
  'ViewCase?caseId=',
  "result:'ALREADY_EXISTS',read_back:true",
  "result:'SENT',read_back:true",
];
foreach($scriptRequired as $needle){if(strpos($scriptText,$needle)===false){fwrite(STDERR,"amazon support Bastion site script missing contract: {$needle}\n");exit(1);}}
$scriptForbidden=[
  'sv_systemctl stop amazon-returns-seller-central-browser.service',
  'seller-central-safe-t-read-worker.mjs --auth-check',
  'SELLER_SUPPORT_OPEN',
  'create new case',
  'open new case',
];
foreach($scriptForbidden as $needle){if(strpos($scriptText,$needle)!==false){fwrite(STDERR,"amazon support Bastion site script contains forbidden pattern: {$needle}\n");exit(1);}}

echo "amazon-support-bastion-breakglass-contract: ok\n";
