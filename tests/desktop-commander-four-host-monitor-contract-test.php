<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$workflows = glob($root . '/.github/workflows/*desktop-commander*.yml') ?: [];
$scheduled = [];
foreach ($workflows as $path) {
    $text = (string) file_get_contents($path);
    if (strpos($text, "cron: '*/5 * * * *'") !== false) {
        $scheduled[] = basename($path);
    }
}
if ($scheduled !== ['desktop-commander-24h-health.yml']) {
    fwrite(STDERR, 'scheduled DC monitors must be singular: ' . implode(',', $scheduled) . "\n");
    exit(1);
}
$health = (string) file_get_contents($root . '/.github/workflows/desktop-commander-24h-health.yml');
foreach (['LAPTOP-NIG4IFUU','DESKTOP-KOCEPSV','shopvivaliz-a1-backend','shopvivaliz-free-a1','shopvivaliz-free-a1-monitor','Sanitized four-host health only.'] as $needle) {
    if (strpos($health, $needle) === false) {
        fwrite(STDERR, "four-host monitor missing {$needle}\n");
        exit(1);
    }
}
echo "desktop-commander-four-host-monitor-contract: ok\n";
