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
foreach (['CANONICAL_AGENT_COUNT','NONCANONICAL_AGENT_COUNT','TASK_LOGON_TYPE','TASK_RUN_LEVEL'] as $needle) {
    if (strpos($status, $needle) === false) {
        fwrite(STDERR, "FALHOU: status Fred-Win sem {$needle}\n");
        exit(1);
    }
}
if (strpos($status, '$authRequired = (Test-Path -LiteralPath $CooldownFile)') === false) {
    fwrite(STDERR, "FALHOU: status Fred-Win nao usa cooldown atual\n");
    exit(1);
}
if (strpos($status, 'Get-Content -LiteralPath $LogFile -Tail') !== false) {
    fwrite(STDERR, "FALHOU: status Fred-Win usa AUTH_REQUIRED historico do log\n");
    exit(1);
}
foreach (['Get-CanonicalRemoteLaunchers','DesktopCommanderHidden','DesktopCommanderUser24x7','desktop-commander.vbs'] as $needle) {
    if (strpos($supervisor, $needle) === false) {
        fwrite(STDERR, "FALHOU: supervisor Fred-Win sem convergencia {$needle}\n");
        exit(1);
    }
}
if (strpos($supervisor, "'InstallTask' { Install-Task;") === false) {
    fwrite(STDERR, "FALHOU: InstallTask ausente\n");
    exit(1);
}
echo "desktop-commander-persist-session-contract: ok\n";
