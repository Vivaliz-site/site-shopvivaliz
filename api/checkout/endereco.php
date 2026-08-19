<?php
declare(strict_types=1);
/**
 * Rodada 5 (2026-08-19): endpoint neutralizado.
 *
 * require_once __DIR__ . '/../includes/viacep-integration.php' apontava para
 * api/includes/viacep-integration.php, que nunca existiu -- o arquivo real
 * fica em includes/viacep-integration.php (raiz do repo), dois niveis acima
 * daqui. Todo request pra este arquivo sempre resultava em fatal error
 * (require de arquivo inexistente). Confirmado via grep em todo o repo que
 * nenhum outro arquivo (PHP/JS/HTML) referencia "checkout/endereco" -- o
 * fluxo de checkout real de endereco/CEP roda por outro caminho. Ver R5-7 no
 * relatorio da Rodada 5.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'endpoint_removed',
    'message' => 'Este endpoint foi desativado (caminho de include quebrado, sem uso real no site).',
]);
