<?php
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
  'ComputeInstanceAgentClient',
  'create_instance_agent_command',
  'base64.b64encode',
  'chunk_size = 2500',
  'append-amazon-support-script',
  'compute instance action',
  'SELLER_SUPPORT_OPEN',
  'create new case',
  'open new case',
];
foreach($forbidden as $needle){if(strpos($text,$needle)!==false){fwrite(STDERR,"amazon support Bastion breakglass contains forbidden pattern: {$needle}\n");exit(1);}}
echo "amazon-support-bastion-breakglass-contract: ok\n";
