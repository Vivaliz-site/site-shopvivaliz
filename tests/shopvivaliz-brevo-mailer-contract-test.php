<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mailer = file_get_contents($root . '/scripts/mailer.php') ?: '';
$orderService = file_get_contents($root . '/includes/OrderNotificationService.class.php') ?: '';
$autonomous = file_get_contents($root . '/api/autonomous/send-email.php') ?: '';
$boleto = file_get_contents($root . '/api/generate-boleto-email.php') ?: '';

$errors = [];
foreach ([
    "BREVO_API_KEY" => 'mailer must require Brevo API key',
    "'provider' => 'brevo_api'" => 'mailer must select Brevo API',
    "atendimento@shopvivaliz.com.br" => 'fixed ShopVivaliz sender missing',
    "replyTo" => 'fixed reply-to missing',
    "api.brevo.com/v3/smtp/email" => 'Brevo endpoint missing',
] as $needle => $message) {
    if (!str_contains($mailer, $needle)) $errors[] = $message;
}

foreach (['smtp.titan.email', 'smtp.gmail.com', 'send_email_native(', 'return mail('] as $forbidden) {
    if (str_contains($mailer, $forbidden)) $errors[] = "mailer forbidden fallback: $forbidden";
}
foreach (['Contabilidade Melo', 'ContabilidadeMelo', 'fiscalmelo', 'naoresponda@dev.shopvivaliz.com.br'] as $forbidden) {
    if (!str_contains($mailer, 'forbidden_brand_content')) {
        $errors[] = 'brand contamination guard missing';
        break;
    }
}

if (!str_contains($orderService, "require_once __DIR__ . '/../scripts/mailer.php'")) {
    $errors[] = 'OrderNotificationService must use central mailer';
}
if (str_contains($orderService, "new \\PHPMailer\\PHPMailer\\PHPMailer")) {
    $errors[] = 'OrderNotificationService still bypasses central mailer';
}
if (str_contains($autonomous, 'smtp.gmail.com') || str_contains($autonomous, 'mail(')) {
    $errors[] = 'autonomous sender still bypasses central mailer';
}
if (str_contains($boleto, 'mail($emailTo')) {
    $errors[] = 'boleto endpoint still uses mail()';
}


$governance = file_get_contents($root . '/scripts/repository-governance-validate.sh') ?: '';
foreach ([
    'tests/shopvivaliz-brevo-mailer-contract-test.php',
    'tests/shopvivaliz-brevo-mailer-runtime-test.php',
    'tests/shopvivaliz-mail-legacy-provider-guard-test.py',
    'tests.test_stock_alerts_brevo_mail',
] as $guard) {
    if (!str_contains($governance, $guard)) $errors[] = "repository governance missing mail guard: $guard";
}

$envExample = file_get_contents($root . '/.env.example') ?: '';
$credentialWorkflow = file_get_contents($root . '/.github/workflows/merge-runtime-credential-union.yml') ?: '';
if (!str_contains($envExample, 'BREVO_API_KEY=')) $errors[] = 'env example must document Brevo API';
if (str_contains($envExample, 'smtp.titan.email')) $errors[] = 'env example still advertises Titan SMTP';
if (!str_contains($credentialWorkflow, '"provider", "api_key", "from_email", "reply_to_email"')) {
    $errors[] = 'email credential workflow must validate Brevo mailer contract';
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "shopvivaliz_brevo_mailer_contract=PASS\n";
