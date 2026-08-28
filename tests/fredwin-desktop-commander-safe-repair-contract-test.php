<?php
$root = dirname(__DIR__);
$path = $root . '/.github/workflows/fred-win-desktop-commander-action.yml';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: workflow Fred-Win ausente\n"); exit(1); }
$yml = file_get_contents($path);
foreach ([
    'scripts/fredwin-desktop-commander-supervisor.ps1',
    'scripts/fredwin-desktop-commander-runner.ps1',
    'scripts/patch-desktop-commander-session-persistence.mjs',
    'git restore --source=origin/main --',
    'StrictHostKeyChecking=yes',
    'http://127.0.0.1:5557/health',
] as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "FALHOU: ausente {$needle}\n"); exit(1); }
}
foreach ([
    'git merge --ff-only origin/main',
    "fredwin-remote-bootstrap.ps1' -Mode InstallTask",
    'StrictHostKeyChecking=no',
] as $needle) {
    if (strpos($yml, $needle) !== false) { fwrite(STDERR, "FALHOU: reparo Fred-Win ainda contem {$needle}\n"); exit(1); }
}
echo "fredwin-desktop-commander-safe-repair-contract: ok\n";
