<?php
$root = dirname(__DIR__);
$fred = file_get_contents($root . '/scripts/fredwin-desktop-commander-runner.ps1');
$vm = file_get_contents($root . '/scripts/vm-desktop-commander-supervisor.sh');
$status = file_get_contents($root . '/scripts/fredwin-desktop-commander-status.ps1');
$supervisor = file_get_contents($root . '/scripts/fredwin-desktop-commander-supervisor.ps1');
$health = file_get_contents($root . '/.github/workflows/desktop-commander-24h-health.yml');
if ($fred === false || $vm === false || $status === false || $supervisor === false || $health === false) { fwrite(STDERR, "FALHOU: supervisor/health ausente\n"); exit(1); }
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
foreach (['SESSION_BACKUP_DIR', 'session-backup/device.json', 'install -m 600 "$DEVICE_FILE"'] as $needle) {
    if (strpos($vm, $needle) === false) {
        fwrite(STDERR, "FALHOU: VM sem backup persistente de sessao: {$needle}\n");
        exit(1);
    }
}
foreach (['PROVIDER_CONNECTED', "is_true(values.get('PROVIDER_CONNECTED'))"] as $needle) {
    if (strpos($health, $needle) === false) {
        fwrite(STDERR, "FALHOU: health VM nao valida conexao real do provider: {$needle}\n");
        exit(1);
    }
}
foreach (['structurally_healthy', 'if not auth and not structurally_healthy and MAX_REPAIR_ATTEMPTS == 1:'] as $needle) {
    if (strpos($health, $needle) === false) {
        fwrite(STDERR, "FALHOU: health VM reinicia agente estruturalmente saudavel sem provider: {$needle}\n" );
        exit(1);
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
