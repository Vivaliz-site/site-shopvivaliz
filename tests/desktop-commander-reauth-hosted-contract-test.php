<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/desktop-commander-reauth-runner.yml');

$required = [
    'runs-on: ubuntu-latest',
    'environment: Production',
    'shopvivaliz-free-a1',
    'always-free-arm-1787907847-26',
    '10.0.1.112',
    '10.0.1.38',
    'oci bastion session create-port-forwarding',
    'LAPTOP-NIG4IFUU',
    'DESKTOP-KOCEPSV',
    'target=(all|shopvivaliz-free-a1|always-free-arm-1787907847-26|fred-win|kocepsv)',
    'dc-reauth-shopvivaliz-free-a1.txt',
    'dc-reauth-always-free-arm-1787907847-26.txt',
    'dc-reauth-fred-win.txt',
    'dc-reauth-kocepsv.txt',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "hosted DC reauth workflow missing contract token: {$needle}\n");
        exit(1);
    }
}

if (str_contains($workflow, 'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]')) {
    fwrite(STDERR, "DC reauth must not depend on the A1 self-hosted runner\n");
    exit(1);
}

echo "desktop-commander-reauth-hosted: ok\n";
