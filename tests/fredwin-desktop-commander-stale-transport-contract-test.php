<?php
declare(strict_types=1);
$root = $argv[1] ?? dirname(__DIR__);
$supervisorPath = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$runnerPath = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
foreach ([$supervisorPath, $runnerPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n"); exit(1); }
}
$supervisor = file_get_contents($supervisorPath);
$runner = file_get_contents($runnerPath);
if ($supervisor === false || $runner === false) {
    fwrite(STDERR, "FALHOU: nao foi possivel ler supervisor/runner\n");
    exit(1);
}
$supervisorRequired = [
    '$ConnectedMarker', 'Get-MarkerAgeSeconds', 'Get-LauncherAgeSeconds',
    '$MarkerStaleSeconds = 240', 'marker_age_seconds=', 'REMOTE_AGENT_STARTING=true',
    'catch { return [double]::PositiveInfinity }'
];
foreach ($supervisorRequired as $needle) {
    if (strpos($supervisor, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
foreach (['Get-NetTCPConnection', 'Canonical process exists but transport is stale'] as $needle) {
    if (strpos($supervisor, $needle) !== false) { fwrite(STDERR, "FALHOU: supervisor ainda usa health fragil {$needle}\n"); exit(1); }
}
$runnerRequired = [
    '$DegradedPattern', '$RecoverableChannelPattern', '$RefreshPersistFailurePattern',
    '$DegradedRestartSeconds = 180', '$MarkerRefreshSeconds = 30',
    'Write-ConnectionMarker', 'Provider channel transient event observed; provider kept alive',
    'Provider channel degradation observed', 'Provider channel recovery timed out',
    'SESSION_REFRESH_PERSISTED=false reason=provider_persistence_failure',
    '$rc = if ($null -ne $forcedExitCode)',
    'Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue'
];
foreach ($runnerRequired as $needle) {
    if (strpos($runner, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem {$needle}\n"); exit(1); }
}
if (preg_match('/\$DegradedPattern\s*=.*Channel \(closed\|errored\)/', $runner) === 1) {
    fwrite(STDERR, "FALHOU: eventos recuperaveis ainda armam restart degradado\n");
    exit(1);
}
if (strpos($runner, '$RefreshPersistFailurePattern = \'SESSION_REFRESH_PERSIST_FAILED\'') === false) {
    fwrite(STDERR, "FALHOU: falha explicita de persistencia nao observada\n");
    exit(1);
}
echo "fredwin-desktop-commander-stale-transport-contract: ok\n";