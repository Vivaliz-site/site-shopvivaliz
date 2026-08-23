<?php
$root = dirname(__DIR__);
$unitPath = $root . '/ops/systemd/shopvivaliz-desktop-commander.service';
$installerPath = $root . '/scripts/install-vm-desktop-commander-service.sh';
$supervisorPath = $root . '/scripts/vm-desktop-commander-supervisor.sh';
foreach ([$unitPath,$installerPath,$supervisorPath] as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$unit = file_get_contents($unitPath);
$requiredUnit = [
    'After=network-online.target', 'Wants=network-online.target',
    'User=ubuntu', 'Environment=HOME=/home/ubuntu',
    'Environment=XDG_CONFIG_HOME=/home/ubuntu/.config',
    'Environment=XDG_CACHE_HOME=/home/ubuntu/.cache',
    'Restart=always', 'RestartSec=10',
    'RestartPreventExitStatus=20', 'EnvironmentFile=-/etc/default/shopvivaliz-desktop-commander', 'NoNewPrivileges=true', 'PrivateTmp=true',
    'vm-desktop-commander-supervisor.sh'
];
foreach ($requiredUnit as $needle) {
    if (strpos($unit, $needle) === false) { fwrite(STDERR, "FALHOU: unit sem {$needle}\n"); exit(1); }
}
$installer = file_get_contents($installerPath);
foreach (['sudo -u', 'NPX_BIN', 'systemctl daemon-reload','systemctl enable shopvivaliz-desktop-commander.service','systemctl restart shopvivaliz-desktop-commander.service','is-enabled','is-active'] as $needle) {
    if (strpos($installer, $needle) === false) { fwrite(STDERR, "FALHOU: installer sem {$needle}\n"); exit(1); }
}
$supervisor = file_get_contents($supervisorPath);
foreach (['.desktop-commander-device/device.json','NPX_BIN','@wonderwhy-er/desktop-commander@0.2.47','AUTH_REQUIRED','exit 20','remote'] as $needle) {
    if (strpos($supervisor, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
$all = $unit . $installer . $supervisor;
foreach (['access_token','refresh_token','auth_token','0.0.0.0'] as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: configuracao proibida {$needle}\n"); exit(1); }
}
echo "vm-desktop-commander-service-contract: ok\n";
