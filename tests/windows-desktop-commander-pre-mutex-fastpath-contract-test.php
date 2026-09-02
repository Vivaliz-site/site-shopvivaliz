<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$targets = [
    'fred' => [
        'path' => $root . '/scripts/fredwin-desktop-commander-supervisor.ps1',
        'mutex' => "if (\$Mode -ne 'Status') { \$ownerMutex = Enter-OwnerMutex }",
    ],
    'kocepsv' => [
        'path' => $root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1',
        'mutex' => "if (\$Mode -ne 'InstallTask' -and \$Mode -ne 'Status') { \$ownerMutex = Enter-OwnerMutex }",
    ],
];
foreach ($targets as $name => $cfg) {
    $script = file_get_contents($cfg['path']);
    if ($script === false) { fwrite(STDERR, "{$name}: supervisor missing\n"); exit(1); }
    foreach (['function Test-HealthySingletonFastPath',
              '$canonical.Count -eq 1', '$noncanonical.Count -eq 0',
              '$markerAge -le $MarkerStaleSeconds', 'Test-RecentCooldown'] as $needle) {
        if (strpos($script, $needle) === false) {
            fwrite(STDERR, "{$name}: fast-path missing {$needle}\n"); exit(1);
        }
    }
    $fastCall = strpos($script, "if (\$Mode -eq 'Ensure' -and (Test-HealthySingletonFastPath))");
    $mutexCall = strpos($script, $cfg['mutex']);
    if ($fastCall === false || $mutexCall === false || $fastCall >= $mutexCall) {
        fwrite(STDERR, "{$name}: healthy fast-path must run before mutex acquisition\n"); exit(1);
    }
    $stateFn = strpos($script, 'function Test-DeviceStateNewerThanCooldown');
    $nextFn = strpos($script, 'function ', $stateFn + 10);
    $stateBlock = substr($script, $stateFn, $nextFn - $stateFn);
    foreach (['try {', '-ErrorAction Stop', 'catch { return $false }'] as $needle) {
        if (strpos($stateBlock, $needle) === false) {
            fwrite(STDERR, "{$name}: cooldown state check is not TOCTOU-safe: {$needle}\n"); exit(1);
        }
    }
    $recentFn = strpos($script, 'function Test-RecentCooldown');
    $recentNext = strpos($script, 'function ', $recentFn + 10);
    $recentBlock = substr($script, $recentFn, $recentNext - $recentFn);
    foreach (['try {','-ErrorAction Stop','catch { return $false }'] as $needle) {
        if (strpos($recentBlock, $needle) === false) {
            fwrite(STDERR, "{$name}: recent cooldown check is not TOCTOU-safe: {$needle}\n"); exit(1);
        }
    }
}
echo "windows-desktop-commander-pre-mutex-fastpath-contract: ok\n";
