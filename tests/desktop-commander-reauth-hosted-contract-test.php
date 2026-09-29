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

if (!str_contains($linux, 'Verify this device in your browser:')) {
    fwrite(STDERR, "Linux reauth must extract the provider-issued verification_uri_complete by context\n");
    exit(1);
}

foreach ([
    'script -q -f -c',
    ': > "$SESSION_LOG"',
] as $needle) {
    if (!str_contains($linux, $needle)) {
        fwrite(STDERR, "Linux reauth must use a PTY-backed, pre-created session log: {$needle}\n");
        exit(1);
    }
}
if (!str_contains($windows, 'Verify this device in your browser:')) {
    fwrite(STDERR, "Windows reauth must extract the provider-issued verification_uri_complete by context\n");
    exit(1);
}

if (!str_contains($windows, 'AuthPackageVersion')) {
    fwrite(STDERR, "Windows reauth must support a one-time auth package override\n");
    exit(1);
}
if (substr_count($windows, '$DeviceFile = Join-Path $DeviceDir') !== 1) {
    fwrite(STDERR, "Windows reauth script must contain exactly one canonical device/session implementation\n");
    exit(1);
}
if (!str_contains($windows, "if (\$AuthPackageVersion -notmatch '^0\\.2\\.(48|49|50|51)\$oneShot = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');
if (!str_contains($oneShot, "start_linux site 0.2.51") ||
    !str_contains($oneShot, "start_linux backend 0.2.51") ||
    !str_contains($oneShot, "-AuthPackageVersion '0.2.51'")) {
    fwrite(STDERR, "four-host login must use 0.2.51 only for complete device login URLs\n");
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
)")) {
    fwrite(STDERR, "Windows auth package override validator is malformed or missing\n");
    exit(1);
}
$deviceDirPos = strpos($windows, "\$DeviceDir = Join-Path \$env:USERPROFILE '.desktop-commander-device'");
$deviceFilePos = strpos($windows, "\$DeviceFile = Join-Path \$DeviceDir 'device.json'");
if ($deviceDirPos === false || $deviceFilePos === false || $deviceDirPos > $deviceFilePos) {
    fwrite(STDERR, "Windows reauth must define DeviceDir before DeviceFile\n");
    exit(1);
}
$oneShot = (string) file_get_contents($root . '/.github/workflows/dc-four-host-login-once.yml');
if (!str_contains($oneShot, "start_linux site 0.2.51") ||
    !str_contains($oneShot, "start_linux backend 0.2.51") ||
    !str_contains($oneShot, "-AuthPackageVersion '0.2.51'")) {
    fwrite(STDERR, "four-host login must use 0.2.51 only for complete device login URLs\n");
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
