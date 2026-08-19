<?php
// Desativado em 2026-08-19 (Rodada 4 de melhoria contínua): este endpoint
// (e o arquivo real que ele incluía, claude/api/monitor/simple-chat.php)
// gravava texto arbitrário e ilimitado em disco (storage/logs/monitor-
// messages.log) sem autenticação, sem rate limit, sem limite de tamanho de
// mensagem e sem LOCK_EX no append -- com Access-Control-Allow-Origin: *.
// Qualquer pessoa na internet conseguia encher o disco da VM (que já está
// a ~86% de uso). A resposta também era sempre a mesma mensagem estática,
// então o endpoint não entregava valor real em produção. Ver R4-3 no
// relatório da Rodada 4 de melhoria contínua.
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Endpoint descontinuado.']);
