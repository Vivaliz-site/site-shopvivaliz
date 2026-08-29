<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/fred-win-desktop-commander-action.yml';
$scriptPath = $root . '/scripts/fredwin-desktop-commander-interactive-auth.ps1';
if (!is_file($workflowPath)) { fwrite(STDERR, "FALHOU: workflow Fred-Win ausente\n"); exit(1); }
if (!is_file($scriptPath)) { fwrite(STDERR, "FALHOU: script de autorizacao interativa ausente\n"); exit(1); }
$yml = file_get_contents($workflowPath);
$ps1 = file_get_contents($scriptPath);
foreach ([
    'authorize_interactive)',
    'scripts/fredwin-desktop-commander-interactive-auth.ps1',
    '-Mode Bootstrap',
] as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: workflow ausente {$needle}\n"); exit(1); }
}
foreach ([
    "ValidateSet('Bootstrap','InteractiveWorker')",
    "'Verify Device'",
    'UIAutomationClient',
    'LogonType Interactive',
    'ShopVivaliz DC Interactive Authorization',
    'ShopVivaliz Desktop Commander 24h',
    'fredwin-desktop-commander-runner.ps1',
    'desktop-commander-provider-connected.marker',
    'BUTTON_CLICKED=true',
    'AUTH_COMPLETED=true',
    'CANONICAL_STARTED=true',
] as $needle) {
    if (strpos($ps1, $needle) === false) { fwrite(STDERR, "FALHOU: script ausente {$needle}\n"); exit(1); }
}
foreach ([
    'access_token',
    'refresh_token',
    'device_code',
    'ConvertFrom-Json',
    'Get-Content -LiteralPath $DeviceFile',
    'crypto.randomUUID',
] as $needle) {
    if (stripos($ps1, $needle) !== false) { fwrite(STDERR, "FALHOU: script contem conteudo proibido {$needle}\n"); exit(1); }
}
if (substr_count($ps1, "Equals('Verify Device'") !== 1) {
    fwrite(STDERR, "FALHOU: clique deve ser estritamente no botao Verify Device\n"); exit(1);
}
echo "fredwin-desktop-commander-interactive-auth-contract: ok\n";
