<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/.github/workflows/remote-control-plane-health.yml';
if (!is_file($path)) { fwrite(STDERR, "missing remote control plane workflow\n"); exit(1); }
$yml = (string) file_get_contents($path);
$required = [
    'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]',
    'ubuntu@10.0.1.38',
    'http://127.0.0.1:5557/health',
    'http://127.0.0.1:5558/health',
    'environment=fred-win',
    'environment=desktop-kocepsv',
    'mcp_version',
    'REMOTE_CONTROL_PLANE_STATUS=',
    'StrictHostKeyChecking=yes',
    'UserKnownHostsFile=',
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "missing {$needle}\n"); exit(1); }
}
$forbidden = ['remote_calls_left_pct','PROVIDER_CONNECTED','AUTH_REQUIRED','trycloudflare.com','0.0.0.0:5557','0.0.0.0:5558'];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "forbidden dependency {$needle}\n"); exit(1); }
}
echo "remote-control-plane-primary-contract: ok\n";
