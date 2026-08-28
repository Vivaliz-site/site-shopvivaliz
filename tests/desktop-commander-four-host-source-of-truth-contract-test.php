<?php
$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/vm-desktop-commander-action.yml');
$recover = file_get_contents($root . '/scripts/vm-desktop-commander-recover-session.sh');
$supervisor = file_get_contents($root . '/scripts/vm-desktop-commander-supervisor.sh');

$required = [
    [$workflow, 'install_or_repair)'],
    [$workflow, 'recover_session)'],
    [$supervisor, 'AUTH_GRACE_SECONDS'],
    [$supervisor, 'provider_device_flow_waiting'],
];
foreach ($required as [$haystack, $needle]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FALHOU: ausente {$needle}\n");
        exit(1);
    }
}

$forbiddenWorkflow = [
    '/home/ubuntu/shopvivaliz-deploy/repo',
    'baa8d1cffcae9cc667b8ea07e99de59bba1749d7',
];
foreach ($forbiddenWorkflow as $needle) {
    if (strpos($workflow, $needle) !== false) {
        fwrite(STDERR, "FALHOU: workflow ainda depende de {$needle}\n");
        exit(1);
    }
}

$forbiddenRecover = [
    'systemctl stop "$SERVICE"',
    '/home/ubuntu/.desktop-commander-device*/*.json*',
    'install -m 0600 -o ubuntu -g ubuntu "$candidate" "$TARGET"',
];
foreach ($forbiddenRecover as $needle) {
    if (strpos($recover, $needle) !== false) {
        fwrite(STDERR, "FALHOU: recover inseguro ainda contem {$needle}\n");
        exit(1);
    }
}

echo "desktop-commander-four-host-source-of-truth-contract: ok\n";
