<?php
// Desativado em 2026-08-18 (Rodada 1 de melhoria contínua): este script
// rodava sem autenticação e imprimia SHOW TABLES + schema completo da
// tabela `users` para qualquer visitante (HTTP 200, sem exigir sessão de
// admin). Nao referenciado por nenhum outro arquivo do projeto -- ver
// docs/AGENTS.md / relatorio da Rodada 1 para o achado original.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone.\n";
