<?php
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/desktop-commander-24h-health.yml';
$formatter = $root . '/scripts/desktop-commander-control-plane-status.py';
foreach ([$workflow,$formatter] as $p) {
    if (!is_file($p)) { fwrite(STDERR, "FALHOU: ausente {$p}\n"); exit(1); }
}
$yml = file_get_contents($workflow);
foreach ([
    "cron: '*/5 * * * *'",
    'contents: read',
    'issues: write',
    'Desktop Commander 24h Control Plane Status',
    'LAPTOP-NIG4IFUU',
    'shopvivaliz-ai',
    'DESKTOP-KOCEPSV',
    '127.0.0.1:5557',
    '127.0.0.1:5558',
    'actions/github-script@v7',
    'actions/upload-artifact@v4',
    'retention-days: 30',
    'continue-on-error: true',
    'desktop-commander-control-plane-status.py'
] as $needle) {
    if (stripos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: health workflow sem {$needle}\n"); exit(1); }
}
foreach (['git commit','git push','ops/vm-desktop-commander-request.json','ops/fredwin-desktop-commander-request.json','authorize','read_auth','StrictHostKeyChecking=no'] as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: transporte/acao proibida {$needle}\n"); exit(1); }
}
echo "desktop-commander-control-plane-contract: ok\n";
