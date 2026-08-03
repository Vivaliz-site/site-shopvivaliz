<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenFiles = [
    'fazer-pagamento-real-agora.php',
    'simular-pagamento-real.php',
    'teste-completo-mercadopago.php',
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

echo "OK: no simulated payment, log, or A/B data executors\n";
