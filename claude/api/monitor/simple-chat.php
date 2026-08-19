<?php
// Desativado em 2026-08-19 (Rodada 4 de melhoria contínua): mesma correção
// aplicada em api/simple-agent-chat.php (que incluía este arquivo) --
// gravação ilimitada em disco sem autenticação, sem rate limit, com CORS
// aberto. Este arquivo também era diretamente alcançável via
// /claude/api/monitor/simple-chat.php (a rota claude/ é servida ao público
// por .htaccess:210-211), então precisou ser neutralizado separadamente.
// Ver R4-3 no relatório da Rodada 4 de melhoria contínua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Endpoint descontinuado.']);
