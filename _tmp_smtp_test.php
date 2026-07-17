<?php
require __DIR__ . '/scripts/mailer.php';
$ok = send_email('fredmourao@gmail.com', 'Teste SMTP Gmail ShopVivaliz', '<p>Teste de autenticacao SMTP via Gmail (producao).</p>');
echo $ok ? "OK\n" : "FALHA\n";
