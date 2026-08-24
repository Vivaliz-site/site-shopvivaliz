<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/desktop-commander-24h-health.yml';
$formatterPath = $root . '/scripts/desktop-commander-control-plane-status.py';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: health workflow ausente\n"); exit(1); }
if (!is_file($formatterPath)) { fwrite(STDERR, "FALHOU: formatter ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
foreach ([
    "cron: '*/5 * * * *'", 'issues: write', 'contents: read',
    'MAX_RECOVERY_ATTEMPTS=1', 'LAPTOP-NIG4IFUU', 'shopvivaliz-ai', 'DESKTOP-KOCEPSV',
    '127.0.0.1:5557', '127.0.0.1:5558', 'StrictHostKeyChecking=yes', 'UserKnownHostsFile=',
    'Desktop Commander 24h Control Plane Status', 'actions/github-script@v7', 'actions/upload-artifact@v4',
    'desktop-commander-control-plane-status.py'
] as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: control plane sem {$needle}\n"); exit(1); }
}
foreach (['StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new','authorize.log','AUTH_FLOW_START','device code','git commit','git push'] as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: control plane contem padrao proibido {$needle}\n"); exit(1); }
}
$formatter = file_get_contents($formatterPath);
foreach (['ALLOWED_KEYS','LAPTOP-NIG4IFUU','shopvivaliz-ai','DESKTOP-KOCEPSV','--fred-status','--vm-status','--desktop-status','--json-out','--markdown-out'] as $needle) {
    if (strpos($formatter, $needle) === false) { fwrite(STDERR, "FALHOU: formatter sem {$needle}\n"); exit(1); }
}
echo "desktop-commander-control-plane-contract: ok\n";
