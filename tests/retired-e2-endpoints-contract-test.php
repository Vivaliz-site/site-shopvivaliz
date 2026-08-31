<?php
$root = dirname(__DIR__);
$retired = ['137.131.156.17','136.248.69.116','10.0.1.13','10.0.1.203','shopvivaliz-ai','shopvivaliz-micro-2'];
$extensions = ['sh','ps1','py','php','js','mjs','cjs','ts','tsx','json','toml','ini','conf','service','timer','yml','yaml'];
$explicitFiles = ['mcp-servers.json'];
$roots = ['.github','scripts','ops','config','installer','deploy','automation','automations','tools','agent-bridge'];
$skipParts = ['/.git/','/node_modules/','/vendor/','/docs/','/reports/','/storage/','/backups/','/history/','/scripts/retired/','/tests/'];
$violations = [];

$inspect = static function (string $path) use ($root, $retired, &$violations): void {
    $body = @file_get_contents($path);
    if ($body === false) return;
    foreach ($retired as $token) {
        if (strpos($body, $token) !== false) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $violations[] = $relative . ':' . $token;
            return;
        }
    }
};

foreach (glob($root . '/*') ?: [] as $path) {
    if (!is_file($path)) continue;
    $name = basename($path);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($name, $explicitFiles, true) || in_array($ext, $extensions, true)) $inspect($path);
}

foreach ($roots as $relativeRoot) {
    $dir = $root . '/' . $relativeRoot;
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_ends_with($path, '.disabled')) continue;
        $skip = false;
        foreach ($skipParts as $part) {
            if (strpos($path, $part) !== false) { $skip = true; break; }
        }
        if ($skip) continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) continue;
        $inspect($path);
    }
}

$violations = array_values(array_unique($violations));
sort($violations);
if ($violations) {
    fwrite(STDERR, "Retired E2 endpoint found in active executable/config path:\n" . implode("\n", $violations) . "\n");
    exit(1);
}
echo "retired-e2-endpoints-contract: ok\n";
