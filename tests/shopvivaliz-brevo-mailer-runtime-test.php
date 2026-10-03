<?php
declare(strict_types=1);

putenv('BREVO_API_KEY');
putenv('EMAIL_FROM=Wrong Legacy Identity <wrong@example.com>');
putenv('EMAIL_SMTP_HOST=smtp.gmail.com');
putenv('EMAIL_USER=legacy@example.com');
putenv('EMAIL_PASSWORD=legacy-pass');

require dirname(__DIR__) . '/scripts/mailer.php';

$config = get_mailer_config();
assert($config['provider'] === 'brevo_api');
assert($config['from_email'] === 'atendimento@shopvivaliz.com.br');
assert($config['from_name'] === 'ShopVivaliz');
assert($config['reply_to_email'] === 'atendimento@shopvivaliz.com.br');

$payload = sv_mailer_build_brevo_payload(
    'fredmourao@gmail.com',
    'ShopVivaliz test',
    '<p>Mensagem ShopVivaliz</p>',
    null,
    [['name' => 'test.txt', 'content' => base64_encode('ok')]]
);
assert($payload['sender']['name'] === 'ShopVivaliz');
assert($payload['sender']['email'] === 'atendimento@shopvivaliz.com.br');
assert($payload['replyTo']['email'] === 'atendimento@shopvivaliz.com.br');
assert($payload['attachment'][0]['name'] === 'test.txt');

try {
    sv_mailer_build_brevo_payload(
        'fredmourao@gmail.com',
        'ShopVivaliz test',
        '<p>fiscalmelo</p>'
    );
    throw new RuntimeException('forbidden brand guard did not fire');
} catch (RuntimeException $e) {
    assert($e->getMessage() === 'forbidden_brand_content');
}

$result = send_email_with_result(
    'fredmourao@gmail.com',
    'ShopVivaliz fail closed',
    '<p>test</p>'
);
assert(($result['success'] ?? true) === false);
assert(($result['error'] ?? '') === 'provider_not_configured');

echo "shopvivaliz_brevo_mailer_runtime=PASS\n";
