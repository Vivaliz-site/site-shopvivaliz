<?php
$root = dirname(__DIR__);
$fred = file_get_contents($root . '/scripts/fredwin-desktop-commander-runner.ps1');
$vm = file_get_contents($root . '/scripts/vm-desktop-commander-supervisor.sh');
$status = file_get_contents($root . '/scripts/fredwin-desktop-commander-status.ps1');
$supervisor = file_get_contents($root . '/scripts/fredwin-desktop-commander-supervisor.ps1');
if ($fred === false || $vm === false || $status === false || $supervisor === false) { fwrite(STDERR, "FALHOU: supervisor ausente\n"); exit(1); }
foreach (['fredwin' => $fred, 'vm' => $vm] as $name => $content) {
    if (strpos($content, '--persist-session') === false) {
        fwrite(STDERR, "FALHOU: {$name} sem --persist-session\n");
        exit(1);
    }
    foreach (['access_token', 'refresh_token', 'auth_token'] as $forbidden) {
        if (stripos($content, $forbidden) !== false) {
            fwrite(STDERR, "FALHOU: {$name} contem segredo explicito {$forbidden}\n");
            exit(1);
        }
    }
}
foreach (['Test-Path -LiteralPath $CooldownFile', 'Test-DeviceStateNewerThanCooldown'] as $needle) {
    if (strpos($status, $needle) === false) {
        fwrite(STDERR, "FALHOU: status Fred-Win sem cooldown stale-aware: {$needle}\n");
        exit(1);
    }
}
if (strpos($status, 'Get-Content -LiteralPath $LogFile -Tail') !== false) {
    fwrite(STDERR, "FALHOU: status Fred-Win usa AUTH_REQUIRED historico do log\n");
    exit(1);
}
if (strpos($supervisor, "'InstallTask' { Install-Task; Stop-RemoteProcesses;") === false) {
    fwrite(STDERR, "FALHOU: InstallTask nao reinicia agente com runner atualizado\n");
    exit(1);
}
echo "desktop-commander-persist-session-contract: ok\n";
