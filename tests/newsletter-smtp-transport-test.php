<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../api/newsletter/subscribe.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: subscribe.php could not be read\n");
    exit(1);
}

$required = [
    "require_once dirname(__DIR__, 2) . '/scripts/mailer.php'",
    'send_email($email, $subject, $html, $text)',
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fwrite(STDERR, "FAIL: missing SMTP newsletter transport: {$snippet}\n");
        exit(1);
    }
}

if (preg_match('/\bmail\s*\(/', $source) === 1) {
    fwrite(STDERR, "FAIL: native mail() remains in newsletter subscription flow\n");
    exit(1);
}

echo "newsletter-smtp-transport: ok\n";
