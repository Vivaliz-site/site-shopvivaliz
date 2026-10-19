<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string) file_get_contents($root . '/.github/workflows/desktop-commander-reauth-runner.yml');

$required = [
    '/var/lib/shopvivaliz-remote-control/id_ed25519',
    'sudo -n ssh',
    'DC_REAUTH_WINDOWS_CONTROLLER_KEY=PASS',
    'CONTROLLER_KEY=/var/lib/shopvivaliz-remote-control/id_ed25519',
    'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand',
];

foreach ($required as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "DC reauth controller-key contract missing: {$needle}\n");
        exit(1);
    }
}

if (str_contains($workflow, '-i "$VM_KEY_FILE" "$win_user@127.0.0.1"')) {
    fwrite(STDERR, "Windows DC reauth must not authenticate with the VM bootstrap key\n");
    exit(1);
}

echo "desktop-commander-reauth-controller-key: ok\n";
