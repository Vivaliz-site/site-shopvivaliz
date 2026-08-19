<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Rodada 5 (2026-08-19): este endpoint devolvia publicamente o cadastro ML
// completo da empresa (CNPJ, e-mail, endereco da sede, telefones) -- e um
// proxy anonimo que usa o access token OAuth da loja pra chamar a API do ML.
// Ver R5-1 no relatorio da Rodada 5.
require_once dirname(__DIR__, 2) . '/config/require-agent-key.php';
sv_require_agent_key();

try {
    $data = ml_http_get('https://api.mercadolibre.com/users/me');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[ml_me] ' . $e->getMessage());
    echo json_encode(['error' => 'ml_me_failed']);
}
