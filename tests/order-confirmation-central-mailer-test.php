<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/api/send-order-confirmation-email.php';
$src = file_get_contents($path) ?: '';
$errors = [];
foreach ([
    "require_once __DIR__ . '/../scripts/mailer.php'" => 'central mailer include missing',
    'send_email($customerEmail' => 'central send_email call missing',
] as $needle => $message) {
    if (!str_contains($src, $needle)) $errors[] = $message;
}
foreach ([
    'if (mail(',
    'fsockopen(',
    "verify_peer' => false",
    "getenv('SMTP_PASS') ?: ''",
] as $needle) {
    if (str_contains($src, $needle)) $errors[] = 'legacy transport remains: ' . $needle;
}
if ($errors) {
    foreach ($errors as $e) fwrite(STDERR, 'FAIL: ' . $e . PHP_EOL);
    exit(1);
}
echo "order-confirmation-central-mailer-test: ok\n";
