<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbidden = [
    'vnda' => 'VNDA nao pode ser fonte de catalogo/preco/estoque/pedido/NF',
    'produtos-olist-array.php' => 'array PHP estatico nao pode ser fonte de catalogo',
    'api2/produtos.pesquisa' => 'Tiny API v2 nao pode ser fallback',
    'tiny_v2' => 'sync_source tiny_v2 nao pode ser permitido',
    'TOKEN_API_OLIST' => 'token estatico legado nao pode alimentar runtime',
];
$productionRoots = [
    '.env.example', '.github/workflows', 'api', 'claude', 'config', 'includes',
    'olist', 'scripts', 'daemon-sync-products.py', 'daemon-token-renewer.py',
    'catalogo.php', 'produto.php', 'sync-daemon-to-db.php', 'sync-products-to-json.py',
];
$ignoredPathFragments = [
    '/tests/', '/docs/', '/reports/', '/logs/', '/dist/', '/node_modules/',
    '/vendor/', '/.git/', '/.venv-homologacao/', '/release-notes/', '/ops-snapshots/',
];
$allowedFiles = [
    'tests/erp-api-source-policy-test.php',
];

$errors = [];
foreach ($productionRoots as $entry) {
    $path = $root . '/' . $entry;
    if (!file_exists($path)) continue;
    $files = is_dir($path)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
        : new ArrayIterator([new SplFileInfo($path)]);
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
        foreach ($ignoredPathFragments as $fragment) {
            if (str_contains('/' . $rel, $fragment)) continue 2;
        }
        if (in_array($rel, $allowedFiles, true)) continue;
        if (!preg_match('/\.(php|py|js|mjs|ts|tsx|json|sh)$/', $rel)) continue;
        $content = (string)file_get_contents($file->getPathname());
        foreach ($forbidden as $needle => $reason) {
            if (stripos($content, $needle) !== false) {
                $errors[] = $rel . ': contem "' . $needle . '" - ' . $reason;
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "ERP API source policy failed:\n" . implode("\n", array_slice($errors, 0, 80)) . "\n");
    exit(1);
}

echo "erp-api-source-policy: ok\n";
