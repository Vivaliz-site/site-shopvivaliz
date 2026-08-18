<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): este script
// fazia forca-bruta de credenciais MySQL (10 hosts x 6 usuarios x 5 senhas
// x 4 bancos) e imprimia "SUCESSO: user:senha@host/db" no corpo da
// resposta quando acertava. So era bloqueado por uma entrada avulsa no
// .htaccess -- nenhum controle de acesso proprio. Ver relatorio da
// Rodada 1 de melhoria continua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
