<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/fredwin-desktop-commander-interactive-auth.ps1';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: script ausente\n"); exit(1); }
$ps1 = file_get_contents($path);
foreach ([
    'Stop-ScheduledTask -TaskName $MainTask',
    'Disable-ScheduledTask -TaskName $MainTask',
    'taskkill.exe /PID $runnerProc.Id /T /F',
    'Enable-ScheduledTask -TaskName $MainTask',
    'Start-ScheduledTask -TaskName $MainTask',
] as $needle) {
    if (strpos($ps1, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
foreach ([
    'Invoke-WebRequest',
    'Start-BitsTransfer',
    'curl.exe',
    'Set-Content -LiteralPath $DeviceFile',
    'Remove-Item -LiteralPath $DeviceFile',
] as $needle) {
    if (stripos($ps1, $needle) !== false) { fwrite(STDERR, "FALHOU: atalho inseguro presente {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-interactive-auth-safety-contract: ok\n";
