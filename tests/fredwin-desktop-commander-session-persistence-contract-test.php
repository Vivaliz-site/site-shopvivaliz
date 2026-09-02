<?php
$root = dirname(__DIR__);
$runnerPath = $root . '/scripts/fredwin-desktop-commander-runner.ps1';
$patcherPath = $root . '/scripts/patch-desktop-commander-session-persistence.mjs';
if (!is_file($runnerPath) || !is_file($patcherPath)) {
    fwrite(STDERR, "FALHOU: runner/patcher ausente\n");
    exit(1);
}
$runner = file_get_contents($runnerPath);
foreach ([
    'SessionPatcher',
    'SESSION_REFRESH_PATCH',
    'AuthGraceSeconds',
    'provider authorization grace',
    'Device ready',
    'Test-DeviceStateNewerThanCooldown',
    '$script:ProviderEntryPoint',
    '$psi.FileName = $node',
    'UseShellExecute = $false',
    'CreateNoWindow = $true',
    'RedirectStandardOutput = $true',
    'RedirectStandardError = $true',
] as $needle) {
    if (strpos($runner, $needle) === false) {
        fwrite(STDERR, "FALHOU: Fred-Win sem {$needle}\n");
        exit(1);
    }
}
if (strpos($runner, "Found persisted session|Connected to Remote MCP|WebSocket connected") !== false) {
    fwrite(STDERR, "FALHOU: Fred-Win ainda trata transporte/sessao persistida como broker ready\n");
    exit(1);
}
if (strpos($runner, 'if ((-not $connected) -and ($text -match $AuthPattern))') !== false) {
    fwrite(STDERR, "FALHOU: Fred-Win ainda ignora reauth depois de falso connected\n");
    exit(1);
}
if (strpos($runner, '$psi.FileName = if ($env:ComSpec)') !== false) {
    fwrite(STDERR, "FALHOU: Fred-Win ainda relanca provider via cmd/npx\n");
    exit(1);
}
foreach (['access_token', 'refresh_token', 'auth_token', '-RedirectStandardOutput', '-RedirectStandardError', "'.out'", "'.err'"] as $forbidden) {
    if (stripos($runner, $forbidden) !== false) {
        fwrite(STDERR, "FALHOU: segredo ou captura em disco proibida {$forbidden}\n");
        exit(1);
    }
}
echo "fredwin-desktop-commander-session-persistence-contract: ok\n";
