<?php
$root = $argv[1] ?? dirname(__DIR__);
$supervisorPath = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$runnerPath = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
foreach ([$supervisorPath, $runnerPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n"); exit(1); }
}
$supervisor = file_get_contents($supervisorPath);
$runner = file_get_contents($runnerPath);
$supervisorRequired = [
    '$ConnectedMarker', 'Get-MarkerAgeSeconds', 'Get-LauncherAgeSeconds',
    'marker_age_seconds=', 'REMOTE_AGENT_STARTING=true'
];
foreach ($supervisorRequired as $needle) {
    if (strpos($supervisor, $needle) === false) { fwrite(STDERR, "FALHOU: supervisor sem {$needle}\n"); exit(1); }
}
foreach (['Get-NetTCPConnection', 'Canonical process exists but transport is stale'] as $needle) {
    if (strpos($supervisor, $needle) !== false) { fwrite(STDERR, "FALHOU: supervisor ainda usa health fragil {$needle}\n"); exit(1); }
}
$runnerRequired = [
    '$DegradedPattern', '$DegradedRestartSeconds = 180', '$MarkerRefreshSeconds = 30',
    'Write-ConnectionMarker', 'Provider channel degradation observed',
    'Provider channel recovery timed out', 'SESSION_REFRESH_PERSISTED='
];
foreach ($runnerRequired as $needle) {
    if (strpos($runner, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-stale-transport-contract: ok\n";
