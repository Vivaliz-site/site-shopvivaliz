<?php
$root = dirname(__DIR__);
$path = $root . '/.github/workflows/desktop-commander-three-host-control-plane.yml';
if (!is_file($path)) { fwrite(STDERR, "FALHOU: control plane ausente\n"); exit(1); }
$yml = file_get_contents($path);
foreach ([
    'git restore --source=origin/main --',
    '-Mode Ensure',
    'TASK_LOGON_TYPE',
    'TASK_RUN_LEVEL'
] as $needle) {
    if (strpos($yml, $needle) === false) {
        fwrite(STDERR, "FALHOU: reparo seguro sem {$needle}\n"); exit(1);
    }
}
foreach ([
    'git merge --ff-only origin/main',
    "f'& \\'C:\\\\site-shopvivaliz\\\\scripts\\\\{supervisor}\\' -Mode InstallTask; '"
] as $needle) {
    if (strpos($yml, $needle) !== false) {
        fwrite(STDERR, "FALHOU: reparo central ainda destrutivo {$needle}\n"); exit(1);
    }
}
echo "desktop-commander-three-host-safe-repair-contract: ok\n";
