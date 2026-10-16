<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/backend-control-plane-recovery.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "backend control-plane recovery workflow missing\n");
    exit(1);
}
$src = (string) file_get_contents($workflow);
$required = [
    'name: Backend Control Plane Recovery',
    'workflow_dispatch:',
    'SOFTRESET_UNRESPONSIVE_BACKEND',
    'runs-on: ubuntu-latest',
    'environment: Production',
    'BACKEND_INSTANCE_NAME: always-free-arm-1787907847-26',
    'OCI_CLI_KEY_CONTENT',
    'query instance resources where displayName =',
    'test "$count" = 1',
    'test "$lifecycle" = RUNNING',
    'compute instance action',
    '--action SOFTRESET',
    '--wait-for-state RUNNING',
    'OCI_BACKEND_SOFTRESET=PASS',
];
foreach ($required as $needle) {
    if (strpos($src, $needle) === false) {
        fwrite(STDERR, "missing recovery contract: {$needle}\n");
        exit(1);
    }
}
$forbidden = [
    'curl -fsSL http://',
    'StrictHostKeyChecking=no',
    'sshpass',
    'exit 0',
];
foreach ($forbidden as $needle) {
    if (strpos($src, $needle) !== false) {
        fwrite(STDERR, "unsafe recovery contract: {$needle}\n");
        exit(1);
    }
}
echo "backend-control-plane-recovery-contract: ok\n";
