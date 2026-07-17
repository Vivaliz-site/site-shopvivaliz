<?php
require __DIR__ . '/scripts/mailer.php';
$ok = send_email('fredmourao@gmail.com', 'Teste SMTP Gmail ShopVivaliz (nova senha)', '<p>Teste de autenticacao SMTP via Gmail (producao) com nova senha de app.</p>');
echo $ok ? "OK\n" : "FALHA\n";
