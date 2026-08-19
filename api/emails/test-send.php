<?php
// Desativado em 2026-08-19 (Rodada 3 de melhoria contínua): relay de e-mail
// aberto e não autenticado. O script lia $_GET['token'], calculava
// $expected_token e NUNCA comparava os dois -- qualquer visitante disparava
// e-mails transacionais reais (confirmação de pedido, boleto gerado, pedido
// enviado) para QUALQUER destinatário via $_GET['email'], usando o SMTP e o
// domínio da loja. Confirmado ao vivo: HTTP 200 e envio real sem nenhuma
// credencial. Risco principal: queima de reputação SPF/DKIM (os e-mails de
// pedidos de clientes reais passam a cair em spam) e phishing assinado pelo
// domínio real. Nenhum fluxo de produção depende deste endpoint. Ver R3-1 no
// relatório da Rodada 3 de melhoria contínua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Endpoint descontinuado.']);
