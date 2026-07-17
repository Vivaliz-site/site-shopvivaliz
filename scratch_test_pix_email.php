<?php
declare(strict_types=1);

foreach (file(__DIR__ . '/.env') as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
}

require __DIR__ . '/scripts/mailer.php';

// QR code Pix de teste (payload EMV falso, so pra validar o email/embed) + PNG 1x1 base64 valido
$fakeQrCode = '00020126580014BR.GOV.BCB.PIX0136teste-fake-nao-e-um-pix-real520400005303986540510.005802BR5913ShopVivaliz Teste6008Sao Paulo62070503***6304ABCD';
$fakePngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

$to = 'fredmourao@gmail.com';

$ok = svmp_send_pix_qr_email($to, 'Fred (teste)', 'TESTE-PIX-EMAIL-001', 123.45, $fakeQrCode, $fakePngBase64);

echo $ok ? "OK: email enviado para $to\n" : "FALHA: svmp_send_pix_qr_email retornou false\n";
