<?php
require __DIR__ . '/scripts/mailer.php';
$ok = send_email('fredmourao@gmail.com', 'Teste SMTP Gmail ShopVivaliz (retry)', '<p>Teste de autenticacao SMTP via Gmail (producao), email shopvivaliz@gmail.com com senha de app nova.</p>');
echo $ok ? "OK\n" : "FALHA\n";
