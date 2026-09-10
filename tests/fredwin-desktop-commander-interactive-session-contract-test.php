<?php
$root = dirname(__DIR__);
$s = file_get_contents($root . '/scripts/fredwin-desktop-commander-supervisor.ps1');
if ($s === false) { throw new RuntimeException('supervisor missing'); }
$checks = [
    '-AtLogOn -User $user',
    '-LogonType Interactive -RunLevel Highest',
    '-Principal $primaryPrincipal',
    '-Principal $guardianPrincipal',
    '-LogonType S4U -RunLevel Highest',
    'primary_logon=Interactive guardian_logon=S4U',
];
foreach ($checks as $needle) {
    if (strpos($s, $needle) === false) { fwrite(STDERR, "missing: {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-interactive-session-contract-test: OK\n";

