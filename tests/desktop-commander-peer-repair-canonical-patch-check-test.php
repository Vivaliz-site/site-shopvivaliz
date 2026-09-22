<?php
$src = file_get_contents(__DIR__ . '/../.github/workflows/desktop-commander-peer-repair.yml');
if (strpos($src, 'grep -RFl "Proactively refresh credentials before the realtime expiry window (v4)."') === false) {
    fwrite(STDERR, "peer repair must locate the patched runtime by marker
");
    exit(1);
}
if (strpos($src, 'find "$HOME/.npm/_npx" -path "*/node_modules/@wonderwhy-er/desktop-commander/dist/remote-device/device.js" -type f | head -1') !== false) {
    fwrite(STDERR, "peer repair must not select an arbitrary npm cache entry
");
    exit(1);
}
echo "desktop-commander-peer-repair-canonical-patch-check: ok
";
