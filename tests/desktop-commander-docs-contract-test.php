<?php
$root = dirname(__DIR__);
$paths = [
    'overview' => $root . '/docs/DESKTOP-COMMANDER-24H.md',
    'fred' => $root . '/docs/FRED-WIN-PRIVATE-RELAY.md',
    'desktop' => $root . '/docs/DESKTOP-KOCEPSV-PRIVATE-RELAY.md',
    'index' => $root . '/AGENTS-ACCESS-INDEX.md',
    'map' => $root . '/docs/ai-agents-map.md',
];
foreach ($paths as $name => $path) {
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: doc {$name} ausente\n"); exit(1); }
}
$overview = file_get_contents($paths['overview']);
foreach (['LAPTOP-NIG4IFUU','shopvivaliz-ai','DESKTOP-KOCEPSV','@wonderwhy-er/desktop-commander@0.2.47','5557','5558','Desktop Commander 24h Control Plane Status','AUTH_REQUIRED','GitHub Actions'] as $needle) {
    if (strpos($overview, $needle) === false) { fwrite(STDERR, "FALHOU: overview sem {$needle}\n"); exit(1); }
}
$fred = file_get_contents($paths['fred']);
foreach (['ShopVivaliz FredWin Relay 24h','LogonType S4U','StrictHostKeyChecking=yes','5557'] as $needle) {
    if (strpos($fred, $needle) === false) { fwrite(STDERR, "FALHOU: Fred doc sem {$needle}\n"); exit(1); }
}
$desktop = file_get_contents($paths['desktop']);
foreach (['DESKTOP-KOCEPSV','ShopVivaliz DESKTOP-KOCEPSV Relay 24h','ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h','LogonType S4U','StrictHostKeyChecking=yes','5558'] as $needle) {
    if (strpos($desktop, $needle) === false) { fwrite(STDERR, "FALHOU: DESKTOP doc sem {$needle}\n"); exit(1); }
}
foreach (['index','map'] as $name) {
    $content = file_get_contents($paths[$name]);
    foreach (['Desktop Commander 24h Control Plane Status','LAPTOP-NIG4IFUU','shopvivaliz-ai','DESKTOP-KOCEPSV'] as $needle) {
        if (strpos($content, $needle) === false) { fwrite(STDERR, "FALHOU: {$name} sem {$needle}\n"); exit(1); }
    }
}
$combined = implode("\n", array_map('file_get_contents', array_values($paths)));
foreach (['read_auth','"action": "authorize"','StrictHostKeyChecking=no','StrictHostKeyChecking=accept-new'] as $needle) {
    if (stripos($combined, $needle) !== false) { fwrite(STDERR, "FALHOU: docs contem orientacao obsoleta {$needle}\n"); exit(1); }
}
echo "desktop-commander-docs-contract: ok\n";
