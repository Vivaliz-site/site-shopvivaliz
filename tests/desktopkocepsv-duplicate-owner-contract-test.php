<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/scripts/desktopkocepsv-desktop-commander-supervisor.ps1';
$s = file_get_contents($path);
if ($s === false) { fwrite(STDERR, "KOCEPSV supervisor missing\n"); exit(1); }
foreach (['function Test-LauncherOwnedByRunner','function Remove-DuplicateCanonicalLaunchers','Duplicate canonical launcher removed','kept_managed_pid=','$MarkerStaleSeconds = 240'] as $needle) {
    if (strpos($s, $needle) === false) { fwrite(STDERR, "KOCEPSV duplicate-owner hardening missing {$needle}\n"); exit(1); }
}
$guard = 'if ($canonical.Count -gt 0 -and ($canonical.Count -gt 1 -or $noncanonical.Count -gt 0))';
$start = strpos($s, $guard);
$healthy = strpos($s, 'if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0)', $start ?: 0);
if ($start === false || $healthy === false || $healthy <= $start) { fwrite(STDERR, "KOCEPSV duplicate guard ordering invalid\n"); exit(1); }
$block = substr($s, $start, $healthy - $start);
if (strpos($block, 'Stop-RemoteProcesses') !== false) { fwrite(STDERR, "KOCEPSV duplicate guard still kills healthy managed launcher\n"); exit(1); }
if (strpos($block, 'Remove-DuplicateCanonicalLaunchers') === false) { fwrite(STDERR, "KOCEPSV duplicate selective removal missing\n"); exit(1); }
echo "desktopkocepsv-duplicate-owner-contract: ok\n";