<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): conectava
// direto no MySQL de producao usando as constantes de config/constants.php
// e imprimia contagens de tabelas sem autenticacao. Sem controle de acesso
// proprio, so bloqueado por entrada avulsa no .htaccess. Ver relatorio da
// Rodada 1 de melhoria continua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
