<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): script de
// manutencao manual que rodava DELETE direto no banco de producao sem
// autenticacao nem confirmacao. Sem controle de acesso proprio, so
// bloqueado por entrada avulsa no .htaccess. Ver relatorio da Rodada 1 de
// melhoria continua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
