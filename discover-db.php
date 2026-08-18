<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): lia .env via
// regex e imprimia DB_PASS/tokens em texto claro. Sem controle de acesso
// proprio, so bloqueado por entrada avulsa no .htaccess. Ver relatorio da
// Rodada 1 de melhoria continua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
