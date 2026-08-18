<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): este script
// rodava sem autenticação e imprimia nome + e-mail dos últimos 5 pedidos
// reais (HTTP 200, sem exigir sessão de admin). So nao vazava dados no
// momento em que foi encontrado por acidente (coluna 'customer_name'
// desalinhada com o schema atual) -- nao por controle de acesso. Ver
// relatorio da Rodada 1 de melhoria continua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
