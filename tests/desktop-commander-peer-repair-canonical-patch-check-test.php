<?php
$src = file_get_contents(__DIR__ . '/../.github/workflows/desktop-commander-peer-repair.yml');
if (strpos($src, 'while IFS= read -r candidate; do') === false ||
    strpos($src, 'grep -Fq "Proactively refresh credentials before the realtime expiry window (v4)." "$candidate"') === false) {
    fwrite(STDERR, "peer repair must locate the patched runtime by marker without bypasses
");
    exit(1);
}
if (strpos($src, '|| true') !== false) {
    fwrite(STDERR, "peer repair canonical patch check must remain fail-closed
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
