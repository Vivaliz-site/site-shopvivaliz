<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/vm-desktop-commander-action.yml';
$requestPath = $root . '/ops/vm-desktop-commander-request.json';
$recoverPath = $root . '/scripts/vm-desktop-commander-recover-session.sh';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: workflow VM ausente\n"); exit(1); }
if (!is_file($requestPath)) { fwrite(STDERR, "FALHOU: request VM ausente\n"); exit(1); }
if (!is_file($recoverPath)) { fwrite(STDERR, "FALHOU: recovery VM ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$recover = file_get_contents($recoverPath);
$required = [
    'status)', 'install_or_repair)', 'restart)', 'recover_session)', 'kill_for_recovery_test)',
    'Action not allowlisted', 'SHOPVIVALIZ_VM_SSH_KEY',
    'install-vm-desktop-commander-service.sh',
    'shopvivaliz-desktop-commander.service',
    'systemctl is-enabled shopvivaliz-desktop-commander.service',
    'systemctl is-active shopvivaliz-desktop-commander.service'
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
if (strpos($recover, '/home/ubuntu/.desktop-commander-device/session-backup/device.json') === false) { fwrite(STDERR, "FALHOU: recovery nao busca backup persistente explicito\n"); exit(1); }
if (strpos($yml, '$GITHUB_SHA/scripts/vm-desktop-commander-recover-session.sh') === false) { fwrite(STDERR, "FALHOU: recovery workflow nao usa script do commit corrente\n"); exit(1); }
$json = json_decode(file_get_contents($requestPath), true);
if (!is_array($json) || !isset($json['action'])) { fwrite(STDERR, "FALHOU: request invalido\n"); exit(1); }
echo "vm-desktop-commander-action-contract: ok\n";
