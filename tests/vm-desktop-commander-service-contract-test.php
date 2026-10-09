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
foreach ([
    'sudo -u', 'NODE_BIN', 'NPM_BIN', "DC_INSTALL_ROOT='/opt/shopvivaliz-desktop-commander'", '"$NPM_BIN" install --prefix "$DC_INSTALL_ROOT"', 'systemctl daemon-reload',
    'systemctl enable "$SERVICE"', 'systemctl restart "$SERVICE"',
    'LEGACY_SERVICE=', 'desktop-commander.service', 'disable --now',
    'kill_tree', 'CANONICAL_REMOTE_COUNT', 'NONCANONICAL_REMOTE_COUNT',
    '@wonderwhy-er/desktop-commander@0.2.48', 'desktop-commander/dist/index.js remote --persist-session',
    'for attempt in {1..12}', 'sleep 5',
    'is-enabled', 'is-active'
] as $needle) {
    if (strpos($installer, $needle) === false) { fwrite(STDERR, "FALHOU: installer sem {$needle}\n"); exit(1); }
}
$supervisor = file_get_contents($supervisorPath);
$requiredSupervisor = [
    'DEVICE_DIR="$HOME_DIR/.desktop-commander-device"',
    'DEVICE_FILE="$DEVICE_DIR/device.json"',
    'DC_BIN="${DC_BIN:-/opt/shopvivaliz-desktop-commander/node_modules/.bin/desktop-commander}"','@wonderwhy-er/desktop-commander@0.2.48','AUTH_REQUIRED','exit 20','setsid "$DC_BIN" remote --persist-session'
];
foreach ($requiredSupervisor as $needle) {
    if (strpos($supervisor, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
if (strpos($supervisor, 'HOME_DIR="${HOME:-/home/ubuntu}"') === false) {
    fwrite(STDERR, "FALHOU: supervisor sem HOME_DIR canonico\n");
    exit(1);
}

if (strpos($supervisor, 'NPX_BIN=') !== false || strpos($supervisor, 'npx --yes') !== false) {
    fwrite(STDERR, "FALHOU: runtime ainda depende de cache efemero npx\n");
    exit(1);
}
if (strpos($installer, 'DC_BIN=%s') === false) {
    fwrite(STDERR, "FALHOU: installer nao persiste DC_BIN estavel\n");
    exit(1);
}

$all = $unit . $installer . $supervisor;
$forbidden = [
    'access_token','refresh_token','auth_token','0.0.0.0',
    'set +' . 'e', 'tee "$tmp"', 'pkill -f node'
];
foreach ($forbidden as $needle) {
    if (stripos($all, $needle) !== false) { fwrite(STDERR, "FALHOU: configuracao proibida {$needle}\n"); exit(1); }
}
echo "vm-desktop-commander-service-contract: ok\n";
