<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
require __DIR__ . '/scripts/mailer.php';
$cfg = get_mailer_config();
echo "host=" . $cfg['smtp_host'] . " user=" . $cfg['smtp_user'] . " pass_len=" . strlen($cfg['smtp_pass']) . "\n";
$ok = send_email('fredmourao@gmail.com', 'Teste SMTP Gmail ShopVivaliz (com bootstrap-env)', '<p>Teste correto, com bootstrap-env carregado.</p>');
echo $ok ? "OK\n" : "FALHA\n";
