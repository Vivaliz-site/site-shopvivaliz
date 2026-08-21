<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenFiles = [
    'fazer-pagamento-real-agora.php',
    'simular-pagamento-real.php',
    'teste-completo-mercadopago.php',
    'admin/mercadopago-sandbox.php',
    'admin/test-auto-sync.sh',
    'admin/webhook-test.php',
    'api/sandbox/create-preference.php',
    'api/sandbox/webhook-mercadopago.php',
    'claude/api/get-produtos-198.php',
    'includes/mercadopago-sandbox.php',
    'olist/produtos-198.php',
    'scripts/log-simulator.py',
];

foreach ($forbiddenFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (is_file($path)) {
        fwrite(STDERR, "Forbidden simulated executable exists: {$relativePath}\n");
        exit(1);
    }
}

$abTesterPath = $root . '/scripts/abtest/ab_tester.py';
$abTester = file_get_contents($abTesterPath);
if (!is_string($abTester)) {
    fwrite(STDERR, "Unable to read A/B tester.\n");
    exit(1);
}

$forbiddenPatterns = [
    '/simulate_test_data/i',
    '/simula\s+dados\s+de\s+teste/i',
    '/Agent response simulated/i',
    '/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/i',
];

foreach ($forbiddenPatterns as $pattern) {
    if (preg_match($pattern, $abTester) === 1) {
        fwrite(STDERR, "Synthetic A/B testing pattern found: {$pattern}\n");
        exit(1);
    }
}

$operationalFiles = [
    'admin/index.php',
    'admin/menu-completo.php',
    'olist/admin.php',
    'tools/external-smoke-test.php',
];
$forbiddenOperationalSnippets = [
    'sync-products.php?dry_run',
    'mercadopago-sandbox.php',
    'webhook-test.php',
    'test-auto-sync.sh',
    'produtos-198.php',
];

foreach ($operationalFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        fwrite(STDERR, "Unable to read operational file: {$relativePath}\n");
        exit(1);
    }

    foreach ($forbiddenOperationalSnippets as $snippet) {
        if (str_contains($contents, $snippet)) {
            fwrite(STDERR, "Forbidden simulated operational route found in {$relativePath}: {$snippet}\n");
            exit(1);
        }
    }
}

echo "OK: no simulated payment, log, or A/B data executors\n";
