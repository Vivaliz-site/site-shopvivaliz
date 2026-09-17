<?php
$root = dirname(__DIR__);
$primaryPath = $root . '/.github/workflows/remote-control-plane-health.yml';
$dcPath = $root . '/.github/workflows/desktop-commander-24h-health.yml';
foreach ([$primaryPath, $dcPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "missing $path\n"); exit(1); }
}
$primary = file_get_contents($primaryPath);
$dc = file_get_contents($dcPath);
foreach (['Desktop Commander fallback/provider health', 'DC_FALLBACK_PROVIDER_HEALTH'] as $needle) {
    if (strpos($dc, $needle) === false) { fwrite(STDERR, "DC health missing fallback/provider marker: $needle\n"); exit(1); }
}
foreach (['PROVIDER_CONNECTED', 'AUTH_REQUIRED'] as $needle) {
    if (strpos($primary, $needle) !== false) { fwrite(STDERR, "primary control plane must not inspect $needle\n"); exit(1); }
}
if (stripos($dc, 'REMOTE_CONTROL_PLANE_STATUS=failed') !== false) {
    fwrite(STDERR, "DC provider state must not fail the primary remote control plane\n"); exit(1);
}
if (strpos($primary, 'REMOTE_CONTROL_PLANE_STATUS=') === false) {
    fwrite(STDERR, "primary control plane status marker missing\n"); exit(1);
}
echo "remote-control-plane-monitor-separation-contract: ok\n";
