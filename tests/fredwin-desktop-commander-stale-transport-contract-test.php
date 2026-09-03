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

// Real transport liveness: a silently dead channel (no DegradedPattern/
// RecoverableChannelPattern text ever printed) must not refresh the marker
// forever. This lives in the RUNNER (throttled + graced by
// DegradedRestartSeconds), never as an instant check in the supervisor --
// that exact approach (Test-CanonicalTransport, checked once per minute
// with no tolerance) was already tried and reverted for being fragile.
$transportRequired = [
    'Get-NetTCPConnection', 'Test-BrokerTransportEstablished',
    '$TransportCheckIntervalSeconds = 10', '$transportDegradedSinceUtc',
    'no established transport', 'reason=no_established_transport',
    'Provider channel recovery observed (transport)',
];
foreach ($transportRequired as $needle) {
    if (strpos($runner, $needle) === false) { fwrite(STDERR, "FALHOU: runner sem verificacao real de transporte: {$needle}\n"); exit(1); }
}
// The throttle gate must exist: the check is only allowed to run after
// TransportCheckIntervalSeconds have elapsed, not on every ~200ms loop tick.
if (strpos($runner, '(((Get-Date).ToUniversalTime() - $lastTransportCheckUtc).TotalSeconds -ge $TransportCheckIntervalSeconds)') === false) {
    fwrite(STDERR, "FALHOU: verificacao de transporte nao esta throttled\n");
    exit(1);
}
// Fail open on a query error -- a transient CIM hiccup must not itself force a restart.
if (!preg_match('/function Test-BrokerTransportEstablished.*?\} catch \{ return \$true \}/s', $runner)) {
    fwrite(STDERR, "FALHOU: verificacao de transporte nao falha aberta em erro de consulta\n");
    exit(1);
}
// The marker must stop refreshing under either detector, pattern-based or transport-based.
if (strpos($runner, '-not $degradedSinceUtc -and -not $transportDegradedSinceUtc') === false) {
    fwrite(STDERR, "FALHOU: renovacao do marker nao respeita degradacao de transporte\n");
    exit(1);
}
echo "fredwin-desktop-commander-stale-transport-contract: ok\n";