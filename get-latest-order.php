<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): mesmo problema
// de verify-order-created.php -- consulta sem autenticação que devolvia
// dados de pedidos reais (nome, e-mail, forma de pagamento) em JSON aberto.
// Ver relatorio da Rodada 1 de melhoria continua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
