<?php
$src = file_get_contents(__DIR__ . '/../.github/workflows/desktop-commander-peer-repair.yml');
if (strpos($src, 'device_js="/opt/shopvivaliz-desktop-commander/node_modules/@wonderwhy-er/desktop-commander/dist/remote-device/device.js"') === false ||
    strpos($src, 'grep -Fq "Proactively refresh credentials before the realtime expiry window (v4)." "$device_js"') === false) {
    fwrite(STDERR, "peer repair must validate the patched stable runtime directly\n");
    exit(1);
}
if (strpos($src, 'find "$HOME/.npm/_npx"') !== false || strpos($src, 'grep -RFl ') !== false) {
    fwrite(STDERR, "peer repair must not depend on ephemeral npm cache or recursive grep bypasses\n");
    exit(1);
}
echo "desktop-commander-peer-repair-canonical-patch-check: ok
";
