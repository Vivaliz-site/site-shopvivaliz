<?php
$root = dirname(__DIR__);
$healthPath = $root . '/.github/workflows/desktop-commander-24h-health.yml';
$controlPath = $root . '/.github/workflows/desktop-commander-three-host-control-plane.yml';
foreach ([$healthPath, $controlPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: workflow ausente {$path}\n"); exit(1); }
}

$health = (string) file_get_contents($healthPath);
foreach (["TASK_EXISTS", "'InstallTask' if", '-Mode {repair_mode}', '-Mode Ensure', 'MAX_REPAIR_ATTEMPTS = 1'] as $needle) {
    if (strpos($health, $needle) === false) {
        fwrite(STDERR, "desktop-commander-24h-health.yml: bounded repair guard ausente: {$needle}\n");
        exit(1);
    }
}
if (preg_match('/bootstrap[^\n]*-Mode InstallTask/i', $health)) {
    fwrite(STDERR, "desktop-commander-24h-health.yml: bootstrap agendado nao pode usar InstallTask\n");
    exit(1);
}

$control = (string) file_get_contents($controlPath);
foreach (['diagnose:', 'Sanitized diagnostic only. No credentials', 'Stage sanitized status evidence'] as $needle) {
    if (strpos($control, $needle) === false) {
        fwrite(STDERR, "desktop-commander-three-host-control-plane.yml: marcador read-only ausente: {$needle}\n");
        exit(1);
    }
}
foreach (['-Mode InstallTask', 'Register-ScheduledTask', 'install_or_repair)', 'git reset --hard', 'git clean -', 'systemctl restart shopvivaliz-desktop-commander'] as $forbidden) {
    if (stripos($control, $forbidden) !== false) {
        fwrite(STDERR, "desktop-commander-three-host-control-plane.yml: mutacao proibida em diagnostico: {$forbidden}\n");
        exit(1);
    }
}

echo "desktop-commander auto-repair guard contract OK\n";
