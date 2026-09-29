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
    'bastion session create-port-forwarding',
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


$linux = (string) file_get_contents($root . '/scripts/desktop-commander-reauth-linux-once.sh');
$windows = (string) file_get_contents($root . '/scripts/desktop-commander-reauth-windows.ps1');
$oneShot = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');

if (!str_contains($linux, 'script -q -f -c')) {
    fwrite(STDERR, "Linux DC auth must run under a PTY so the device-login prompt is emitted\n");
    exit(1);
}
if (!str_contains($linux, ': > "$SESSION_LOG"')) {
    fwrite(STDERR, "Linux DC auth must create the session log before chmod/background launch\n");
    exit(1);
}
if (!str_contains($oneShot, ': > "$d/reauth-session.log"')) {
    fwrite(STDERR, "One-shot Linux fallback must create its session log before chmod/background launch\n");
    exit(1);
}

if (!str_contains($linux, 'Verify this device in your browser:')) {
    fwrite(STDERR, "Linux reauth must extract the provider-issued verification_uri_complete by context\n");
    exit(1);
}
if (!str_contains($windows, 'Verify this device in your browser:')) {
    fwrite(STDERR, "Windows reauth must extract the provider-issued verification_uri_complete by context\n");
    exit(1);
}
if (str_contains($linux, 'verify-device') || str_contains($windows, 'verify-device') || str_contains($workflow, 'verify-device')) {
    fwrite(STDERR, "DC reauth must not hard-code the provider verification URL path\n");
    exit(1);
}
if (!str_contains($workflow, 'dc-peer-discover.py')) {
    fwrite(STDERR, "Windows peer discovery must preserve Tailscale JSON stdin via a script file\n");
    exit(1);
}
if (str_contains($workflow, 'python3 - "$wanted" <<')) {
    fwrite(STDERR, "Windows peer discovery must not consume stdin for both Python source and Tailscale JSON\n");
    exit(1);
}

if (substr_count($workflow, '- name: Upload private reauth links') !== 1) {
    fwrite(STDERR, "DC reauth workflow must contain exactly one artifact-upload step\n");
    exit(1);
}
if (substr_count($workflow, '- name: Cleanup temporary Bastion access and credentials') !== 1) {
    fwrite(STDERR, "DC reauth workflow must contain exactly one cleanup step\n");
    exit(1);
}
if (!str_contains($workflow, "grep -Eq '^https://[^[:space:]]+\$' \"\$out_file\"")) {
    fwrite(STDERR, "Windows reauth link validation must be a complete quoted URL assertion\n");
    exit(1);
}
if (!str_contains($workflow, "grep -Eq '^https://[^[:space:]]+\$' \"\$file\"")) {
    fwrite(STDERR, "Collected reauth link validation must be a complete quoted URL assertion\n");
    exit(1);
}

echo "desktop-commander-reauth-hosted: ok\n";
