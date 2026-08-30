<?php
$root = dirname(__DIR__);
$path = $root . '/scripts/ssh-tunnel-service-managed.ps1';
$body = file_get_contents($path);
if ($body === false) {
    fwrite(STDERR, "missing Fred tunnel script\n");
    exit(1);
}
$checks = [
    'COMPUTERNAME host guard' => stripos($body, '$env:COMPUTERNAME') !== false,
    'Fred host identity' => stripos($body, 'LAPTOP-NIG4IFUU') !== false,
    'strict host verification' => stripos($body, 'StrictHostKeyChecking=yes') !== false,
    'no accept-new fallback' => stripos($body, 'StrictHostKeyChecking=accept-new') === false,
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "fredwin-tunnel-host-boundary: FAIL: {$name}\n");
        exit(1);
    }
}
echo "fredwin-tunnel-host-boundary: ok\n";
