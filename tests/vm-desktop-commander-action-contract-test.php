<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/vm-desktop-commander-action.yml';
$requestPath = $root . '/ops/vm-desktop-commander-request.json';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: workflow VM ausente\n"); exit(1); }
if (!is_file($requestPath)) { fwrite(STDERR, "FALHOU: request VM ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$required = [
    'status)', 'install)', 'restart)', 'kill_for_recovery_test)',
    'Action not allowlisted', 'SHOPVIVALIZ_VM_SSH_KEY',
    'install-vm-desktop-commander-service.sh',
    'shopvivaliz-desktop-commander.service',
    'test "$SERVICE_ENABLED" = enabled',
    'test "$SERVICE_ACTIVE" = active'
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
$forbidden = [
    'cat ~/.desktop-commander-device/device.json',
    'access_token', 'refresh_token', 'auth_token',
    'set +' . 'e'
];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: padrao proibido {$needle}\n"); exit(1); }
}
$json = json_decode(file_get_contents($requestPath), true);
if (!is_array($json) || !isset($json['action'])) { fwrite(STDERR, "FALHOU: request invalido\n"); exit(1); }
echo "vm-desktop-commander-action-contract: ok\n";
