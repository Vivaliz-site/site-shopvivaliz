<?php
$files = [
    __DIR__ . '/../.github/workflows/desktop-commander-24h-health.yml',
    __DIR__ . '/../.github/workflows/desktop-commander-three-host-control-plane.yml',
];
foreach ($files as $file) {
    $text = file_get_contents($file);
    if (strpos($text, "TASK_EXISTS") === false || strpos($text, "'InstallTask' if") === false) {
        fwrite(STDERR, basename($file) . ": InstallTask must be conditional on missing TASK_EXISTS\n");
        exit(1);
    }
    if (strpos($text, '-Mode {repair_mode}') === false && strpos($text, '-Mode {mode}') === false) {
        fwrite(STDERR, basename($file) . ": scheduled DC repair must select Ensure/InstallTask dynamically\n");
        exit(1);
    }
    if (preg_match('/bootstrap[^\n]*-Mode InstallTask/i', $text)) {
        fwrite(STDERR, basename($file) . ": scheduled bootstrap must not use InstallTask\n");
        exit(1);
    }
    if (!preg_match('/bootstrap[^\n]*-Mode Ensure/i', $text)) {
        fwrite(STDERR, basename($file) . ": scheduled bootstrap must use Ensure\n");
        exit(1);
    }
}
echo "desktop-commander auto-repair guard contract OK\n";
