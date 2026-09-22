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
  'concurrency:',
  'group: shopvivaliz-oci-bastion-mutation',
  'cancel-in-progress: false',
  'environment: Production',
  "github.event.issue.number == 1586",
  "github.event.comment.user.login == 'fredmourao-ai'",
  "github.event.comment.body == '/amazon-support-reply case_ids=22153077391,22153259501'",
  "github.event.comment.body == '/amazon-support-readback case_ids=22153077391,22153259501'",
  'scripts/amazon-support-readback-oci-site.sh',
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
  'PROBE_EXEC_FAILED',
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

$readbackScript=$root.'/scripts/amazon-support-readback-oci-site.sh';
if(!is_file($readbackScript)){fwrite(STDERR,"amazon support read-back script missing\n");exit(1);}
$readbackText=(string)file_get_contents($readbackScript);
$readbackRequired=[
  'SearchForCases',
  'ViewCase?caseId=',
  'pageSize=10',
  "result=evidence.found?'ALREADY_EXISTS':'NOT_CONFIRMED'",
  'expected_sha256',
  'matched_sha256',
  'detail_sha256',
  'contact_count',
  'total_contacts',
  'last_outbound_sha256',
  "match_scope:'$.SearchForCases.lastOutboundReply'",
  'lastOutbound.includes(prefix)',
  'SUPPORT_LOOKUP_PROBE=PASS',
];
foreach($readbackRequired as $needle){if(strpos($readbackText,$needle)===false){fwrite(STDERR,"amazon support read-back missing contract: {$needle}\n");exit(1);}}
$readbackForbidden=[
  'SELLER_SUPPORT_OPEN',
  'kat-button',
  'kat-textarea',
  'textarea',
  'SEND_ACTION',
  'REPLY_ACTION',
  'Reopen case',
  'Reabrir caso',
  '. /home/ubuntu/amazon-returns-deploy/shared/.env',
];
foreach($readbackForbidden as $needle){if(strpos($readbackText,$needle)!==false){fwrite(STDERR,"amazon support read-back contains forbidden write path: {$needle}\n");exit(1);}}

$remoteWorkflow=$root.'/.github/workflows/shopvivaliz-remote-access.yml';
if(!is_file($remoteWorkflow)){fwrite(STDERR,"remote access workflow missing\n");exit(1);}
$remoteText=(string)file_get_contents($remoteWorkflow);
$remoteRequired=[
  'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]',
  'amazon_support_readback',
  'amazon_support_reply',
  'action.startswith("amazon_support_")',
  'Amazon Seller Support actions are restricted to the site VM',
  'sudo -n env AMAZON_SUPPORT_READBACK_CASE_IDS=22153259501,22154699381 AMAZON_SUPPORT_READBACK_ALLOW_MISSING=1 bash scripts/amazon-support-readback-oci-site.sh',
  'sudo -n bash scripts/amazon-support-reply-oci-site.sh',
];
foreach($remoteRequired as $needle){if(strpos($remoteText,$needle)===false){fwrite(STDERR,"remote Amazon support control missing contract: {$needle}\n");exit(1);}}

echo "amazon-support-bastion-breakglass-contract: ok\n";
