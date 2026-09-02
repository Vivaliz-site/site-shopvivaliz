<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$runner = file_get_contents($root . '/scripts/fredwin-desktop-commander-runner.ps1');
$supervisor = file_get_contents($root . '/scripts/fredwin-desktop-commander-supervisor.ps1');
if ($runner === false || $supervisor === false) { fwrite(STDERR, "missing runner/supervisor\n"); exit(1); }
foreach (['$script:ProviderEntryPoint', 'Join-Path $packageRoot \'dist\\index.js\'',
    '$psi.FileName = $node', '$script:ProviderEntryPoint +'] as $needle) {
    if (strpos($runner, $needle) === false) { fwrite(STDERR, "runner missing {$needle}\n"); exit(1); }
}
foreach (['$psi.FileName = if ($env:ComSpec)', "remote --persist-session\"'"] as $needle) {
    if (strpos($runner, $needle) !== false) { fwrite(STDERR, "runner still re-resolves provider\n"); exit(1); }
}
foreach (['Test-CanonicalRemoteLauncher', 'Test-LauncherOwnedByRunner', "Name -eq 'node.exe'", 'index\.js'] as $needle) {
    if (strpos($supervisor, $needle) === false) { fwrite(STDERR, "supervisor missing {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-patched-runtime-contract: ok\n";
