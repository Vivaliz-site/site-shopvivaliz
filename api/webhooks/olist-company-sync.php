<?php
/**
 * Webhook: Sincronizar dados da empresa quando alterados no Olist
 * Olist dispara: POST /api/webhooks/olist-company-sync.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Only POST allowed']);
    exit;
}

// Rodada 8 (2026-08-19): rate limit ANTES de ler/gravar nada -- esgotamento
// de disco anonimo era possivel (ver abaixo). Ver R8-6 no relatorio da
// Rodada 8.
require_once dirname(__DIR__, 2) . '/includes/order-rate-limit.php';
if (!svorl_allow(30, 60, 'olist-company-sync')) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'rate_limited']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Verificar token de autenticação
//
// Rodada 8 (2026-08-19): duas correcoes:
// 1. OLIST_WEBHOOK_TOKEN dedicado (padrao ja usado em
//    api/webhooks/order-status-update.php) tem prioridade se estiver
//    configurado; OLIST_SELLER_ID e mantido como fallback pra nao quebrar a
//    integracao real antes do Fred rotacionar o segredo -- seller ID e um
//    identificador de baixa entropia (nao um segredo), nao deveria ser a
//    unica defesa.
// 2. hash_equals() no lugar de !== (evita comparacao suscetivel a timing).
// Ver R8-6 no relatorio da Rodada 8.
$olistToken = (string)(getenv('OLIST_WEBHOOK_TOKEN') ?: getenv('OLIST_SELLER_ID') ?: '');
$headerToken = (string)($_SERVER['HTTP_X_OLIST_TOKEN'] ?? '');

if ($olistToken === '' || $headerToken === '' || !hash_equals(hash('sha256', $olistToken), $headerToken)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Log do webhook -- movido para DEPOIS da autenticacao (Rodada 8, 2026-08-19):
// antes gravava o corpo cru de QUALQUER POST anonimo em disco, sem limite de
// tamanho e sem rotacao -- vetor de esgotamento de disco. Agora so grava
// requisicoes ja autenticadas. Ver R8-6 no relatorio da Rodada 8.
file_put_contents(
    dirname(__DIR__, 2) . '/logs/olist-company-sync.log',
    '[' . date('Y-m-d H:i:s') . '] ' . $method . ' - ' . json_encode($body) . "\n",
    FILE_APPEND
);

// Sincronizar dados da empresa
if (!empty($body['event']) && $body['event'] === 'seller_updated') {
    $sellerData = $body['data'] ?? [];

    $syncPayload = [
        'legal_name'          => $sellerData['legal_name'] ?? '',
        'fantasy_name'        => $sellerData['fantasy_name'] ?? '',
        'address'             => $sellerData['address'] ?? '',
        'number'              => $sellerData['number'] ?? '',
        'complement'          => $sellerData['complement'] ?? '',
        'neighborhood'        => $sellerData['neighborhood'] ?? '',
        'city'                => $sellerData['city'] ?? '',
        'state'               => $sellerData['state'] ?? '',
        'zipcode'             => $sellerData['zipcode'] ?? '',
        'phone'               => $sellerData['phone'] ?? '',
        'mobile'              => $sellerData['mobile'] ?? '',
        'email'               => $sellerData['email'] ?? '',
        'website'             => $sellerData['website'] ?? '',
        'cnpj'                => $sellerData['cnpj'] ?? '',
        'state_registration'  => $sellerData['state_registration'] ?? '',
        'olist_seller_id'     => $sellerData['seller_id'] ?? getenv('OLIST_SELLER_ID'),
    ];

    // Chamar endpoint de sincronização
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => json_encode($syncPayload),
            'timeout' => 10,
        ]
    ]);

    $response = @file_get_contents('http://localhost/api/olist/sync-company-profile.php', false, $context);
    $result = json_decode($response, true) ?? [];

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'message' => 'Sincronização iniciada',
        'sync_result' => $result,
    ]);
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Evento ignorado']);
}
