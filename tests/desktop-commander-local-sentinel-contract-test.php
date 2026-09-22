<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$script = (string) file_get_contents($root . '/scripts/desktop-commander-four-host-sentinel.sh');
$installer = (string) file_get_contents($root . '/scripts/install-desktop-commander-four-host-sentinel.sh');
$service = (string) file_get_contents($root . '/ops/systemd/shopvivaliz-dc-four-host-sentinel.service');
$timer = (string) file_get_contents($root . '/ops/systemd/shopvivaliz-dc-four-host-sentinel.timer');

foreach (['mcp/tool/execute_command','check_windows_dc 5557 fredwin-desktop-commander-status.ps1 interactive','check_windows_dc 5558 desktopkocepsv-desktop-commander-status.ps1 s4u','PROVIDER_CONNECTED','CANONICAL_AGENT_COUNT','10.0.1.112','shopvivaliz-free-a1-monitor','StrictHostKeyChecking=yes','shopvivaliz-desktop-commander.service','shopvivaliz-desktop-commander-guardian.timer'] as $needle) {
    if (!str_contains($script, $needle)) { fwrite(STDERR, "sentinel missing {$needle}\n"); exit(1); }
}
foreach (['OnUnitActiveSec=5min','Persistent=true','RandomizedDelaySec=15s'] as $needle) {
    if (!str_contains($timer, $needle)) { fwrite(STDERR, "timer missing {$needle}\n"); exit(1); }
}
foreach (['StateDirectory=shopvivaliz-dc-four-host-sentinel','NoNewPrivileges=true','ProtectSystem=strict','ProtectHome=read-only'] as $needle) {
    if (!str_contains($service, $needle)) { fwrite(STDERR, "service missing {$needle}\n"); exit(1); }
}
foreach (['137.131.149.55','StrictHostKeyChecking=no'] as $forbidden) {
    if (str_contains($script, $forbidden) || str_contains($installer, $forbidden)) { fwrite(STDERR, "forbidden sentinel pattern {$forbidden}\n"); exit(1); }
}
echo "desktop-commander-local-sentinel-contract: ok\n";
