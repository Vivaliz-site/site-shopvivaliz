<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/.github/workflows/desktop-commander-24h-health.yml';
$text = (string)file_get_contents($path);
$needles = [
    "critical_hosts = {'LAPTOP-NIG4IFUU', 'DESKTOP-KOCEPSV', 'shopvivaliz-a1-backend', 'shopvivaliz-free-a1'}",
    "item.get('host') in critical_hosts",
    "item.get('state') != 'healthy'",
    "healthy = win_healthy(values, expected_logon)",
];
foreach ($needles as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "FAIL: DC scheduled health missing contract: {$needle}\n"); exit(1);
    }
}
foreach (['LAPTOP-NIG4IFUU','DESKTOP-KOCEPSV','shopvivaliz-a1-backend','shopvivaliz-free-a1'] as $host) {
    if (!str_contains($text, $host)) {
        fwrite(STDERR, "FAIL: all four hosts must be critical in scheduled health: {$host}\n"); exit(1);
    }
}
if (str_contains($text, "healthy = win_healthy(values)")) {
    fwrite(STDERR, "FAIL: win_healthy must receive expected_logon after repair\n"); exit(1);
}
fwrite(STDOUT, "PASS: Desktop Commander critical health contract.\n");
