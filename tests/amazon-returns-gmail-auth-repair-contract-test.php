<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=$root.'/.github/workflows/shopvivaliz-remote-access.yml';
$script=$root.'/scripts/amazon-returns-gmail-auth-repair.py';

if(!is_file($workflow)){fwrite(STDERR,"remote workflow missing\n");exit(1);}
if(!is_file($script)){fwrite(STDERR,"gmail auth repair script missing\n");exit(1);}

$workflowText=(string)file_get_contents($workflow);
foreach([
    'amazon_returns_gmail_auth_repair',
    'action.startswith("amazon_returns_")',
    'Amazon Returns actions are restricted to the site VM',
    'sudo -n python3 scripts/amazon-returns-gmail-auth-repair.py',
] as $needle){
    if(strpos($workflowText,$needle)===false){
        fwrite(STDERR,"gmail auth repair workflow contract missing: {$needle}\n");
        exit(1);
    }
}

$scriptText=(string)file_get_contents($script);
foreach([
    '/home/ubuntu/amazon-returns-deploy/shared/.env',
    '/home/ubuntu/shopvivaliz-deploy/shared/.env',
    'oauth2.googleapis.com/token',
    'gmail.googleapis.com/gmail/v1/users/me/profile',
    'GMAIL_OAUTH_CLIENT_ID',
    'GMAIL_OAUTH_CLIENT_SECRET',
    'GMAIL_OAUTH_REFRESH_TOKEN',
    'GOOGLE_OAUTH_CLIENT_ID',
    'GOOGLE_OAUTH_CLIENT_SECRET',
    'GOOGLE_OAUTH_REFRESH_TOKEN',
    'AMAZON_GMAIL_CANDIDATE=',
    'GMAIL_AUTH_REPAIR=',
    'systemctl',
    'amazon-returns-safet.service',
    'workers/amazon-returns/daemon.php',
    '--once',
] as $needle){
    if(strpos($scriptText,$needle)===false){
        fwrite(STDERR,"gmail auth repair script contract missing: {$needle}\n");
        exit(1);
    }
}
foreach([
    'print(refresh',
    'print(client_secret',
    'print(access_token',
    'echo $GMAIL_OAUTH',
    'cat /home/ubuntu/amazon-returns-deploy/shared/.env',
] as $needle){
    if(strpos($scriptText,$needle)!==false){
        fwrite(STDERR,"gmail auth repair script exposes secret-like material: {$needle}\n");
        exit(1);
    }
}

echo "amazon-returns-gmail-auth-repair-contract: ok\n";
