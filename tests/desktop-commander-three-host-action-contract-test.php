<?php
$root = dirname(__DIR__);
$files = [
    'fred' => $root . '/.github/workflows/fred-win-desktop-commander-action.yml',
    'vm' => $root . '/.github/workflows/vm-desktop-commander-action.yml',
    'desktop' => $root . '/.github/workflows/desktopkocepsv-desktop-commander-action.yml',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: workflow {$name} ausente\n"); exit(1); }
    $yml = file_get_contents($path);
    foreach (['status)', 'restart)', 'install_or_repair)', 'kill_for_recovery_test)', 'Action not allowlisted', 'StrictHostKeyChecking=yes', 'UserKnownHostsFile=', 'SHOPVIVALIZ_VM_SSH_KEY', 'SHOPVIVALIZ_VM_KNOWN_HOSTS'] as $needle) {
        if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: {$name} sem {$needle}\n"); exit(1); }
    }
    foreach (['StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new','authorize)','read_auth)','AUTH_FLOW_START','authorize.log','device code','access_token','refresh_token','auth_token'] as $needle) {
        if (stripos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: {$name} contem padrao proibido {$needle}\n"); exit(1); }
    }
}
$fred = file_get_contents($files['fred']);
$desktop = file_get_contents($files['desktop']);
$vm = file_get_contents($files['vm']);
if (strpos($fred, 'http://127.0.0.1:5557') === false) { fwrite(STDERR, "FALHOU: Fred sem relay 5557\n"); exit(1); }
if (strpos($desktop, 'http://127.0.0.1:5558') === false) { fwrite(STDERR, "FALHOU: DESKTOP sem relay 5558\n"); exit(1); }
if (strpos($vm, 'shopvivaliz-desktop-commander.service') === false) { fwrite(STDERR, "FALHOU: VM sem servico canonico\n"); exit(1); }
foreach (['ops/fredwin-desktop-commander-request.json','ops/vm-desktop-commander-request.json','ops/desktopkocepsv-desktop-commander-request.json'] as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: request ausente {$relative}\n"); exit(1); }
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json) || ($json['action'] ?? null) !== 'status') { fwrite(STDERR, "FALHOU: request {$relative} nao esta neutro em status\n"); exit(1); }
}
echo "desktop-commander-three-host-action-contract: ok\n";
