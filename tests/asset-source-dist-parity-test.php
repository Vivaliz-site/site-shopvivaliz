<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/public/dist/asset-manifest.json';
$manifestRaw = @file_get_contents($manifestPath);
$manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
if (!is_array($manifest) || !is_array($manifest['assets'] ?? null)) {
    fwrite(STDERR, "invalid asset manifest\n");
    exit(1);
}

function sv_minify_asset(string $text, string $ext): string
{
    $text = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $text);
    if ($ext === '.js') {
        $text = (string) preg_replace('/^\s*\/\/.*$/m', '', $text);
    }
    $text = (string) preg_replace('/\s+/', ' ', $text);
    if ($ext === '.css') {
        $text = (string) preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $text);
    }
    return trim($text);
}

$missing = [];
$targets = [
    'js' => ['js'],
    'css' => ['css'],
    'public/assets/liz-assistant' => ['js', 'css'],
];
foreach ($targets as $dir => $extensions) {
    $base = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
    if (!is_dir($base)) { continue; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) { continue; }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $extensions, true) || preg_match('/\.min\.(js|css)$/i', $file->getFilename())) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
        if (!isset($manifest['assets'][$relative])) { $missing[] = $relative; }
    }
}
sort($missing, SORT_STRING);
if ($missing !== []) {
    fwrite(STDERR, 'source assets missing from manifest: ' . implode(', ', $missing) . "\n");
    exit(1);
}
$stale = [];
foreach ($manifest['assets'] as $source => $entry) {
    if (!is_string($source) || !is_array($entry) || !is_string($entry['file'] ?? null)) {
        continue;
    }
    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    if (!in_array($ext, ['css', 'js'], true)) {
        continue;
    }
    $sourcePath = $root . str_replace('/', DIRECTORY_SEPARATOR, $source);
    $distPath = $root . str_replace('/', DIRECTORY_SEPARATOR, $entry['file']);
    if (!is_file($sourcePath) || !is_file($distPath)) {
        $stale[] = $source;
        continue;
    }
    $expected = sv_minify_asset((string) file_get_contents($sourcePath), '.' . $ext);
    $actual = trim((string) file_get_contents($distPath));
    if ($expected !== $actual) {
        $stale[] = $source;
    }
}

sort($stale, SORT_STRING);
if ($stale !== []) {
    fwrite(STDERR, 'stale generated assets: ' . implode(', ', $stale) . "\n");
    exit(1);
}
echo "asset-source-dist-parity: ok\n";
