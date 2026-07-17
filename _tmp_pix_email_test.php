<?php
declare(strict_types=1);

if (($_GET['token'] ?? '') !== '3Q3xqbQxTPZASaKvaFXrzhJMLq8Seseh') {
    http_response_code(404);
    exit('not found');
}

require __DIR__ . '/scripts/mailer.php';

$fakeQrCode = '00020126580014BR.GOV.BCB.PIX0136teste-fake-nao-e-um-pix-real520400005303986540510.005802BR5913ShopVivaliz Teste6008Sao Paulo62070503***6304ABCD';
$fakePngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

$to = 'fredmourao@gmail.com';
$ok = svmp_send_pix_qr_email($to, 'Fred (teste)', 'TESTE-PIX-EMAIL-001', 123.45, $fakeQrCode, $fakePngBase64);

header('Content-Type: text/plain; charset=utf-8');
echo $ok ? "OK: email enviado para $to\n" : "FALHA: svmp_send_pix_qr_email retornou false\n";
