<?php
declare(strict_types=1);
$root = $argv[1] ?? dirname(__DIR__);
$path = $root . '/scripts/fredwin-desktop-commander-supervisor.ps1';
$script = file_get_contents($path);
if ($script === false) { fwrite(STDERR, "FALHOU: supervisor unreadable\n"); exit(1); }
$duplicateGuard = 'if ($canonical.Count -gt 0 -and ($canonical.Count -gt 1 -or $noncanonical.Count -gt 0))';
$required = ['function Test-LauncherOwnedByRunner','function Remove-DuplicateCanonicalLaunchers','Duplicate canonical launcher removed','kept_managed_pid=',$duplicateGuard];
foreach ($required as $needle) { if (strpos($script, $needle) === false) { fwrite(STDERR, "FALHOU: duplicate-owner hardening missing {$needle}\n"); exit(1); } }
$blockStart = strpos($script, $duplicateGuard);
$healthStart = strpos($script, 'if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0)', $blockStart ?: 0);
if ($blockStart === false || $healthStart === false || $healthStart <= $blockStart) { fwrite(STDERR, "FALHOU: duplicate handling must precede healthy singleton check\n"); exit(1); }
$block = substr($script, $blockStart, $healthStart - $blockStart);
foreach (['Remove-DuplicateCanonicalLaunchers','Get-CanonicalRemoteLaunchers','Get-NonCanonicalRemoteLaunchers'] as $needle) { if (strpos($block, $needle) === false) { fwrite(STDERR, "FALHOU: duplicate block missing {$needle}\n"); exit(1); } }
if (strpos($block, 'Stop-RemoteProcesses') !== false) { fwrite(STDERR, "FALHOU: duplicate block still kills all canonical sessions\n"); exit(1); }
echo "fredwin-desktop-commander-duplicate-owner-contract: ok\n";
