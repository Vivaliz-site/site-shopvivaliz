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
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Log do webhook
file_put_contents(
    dirname(__DIR__, 2) . '/logs/olist-company-sync.log',
    '[' . date('Y-m-d H:i:s') . '] ' . $method . ' - ' . json_encode($body) . "\n",
    FILE_APPEND
);

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Only POST allowed']);
    exit;
}

// Verificar token de autenticação
$olistToken = getenv('OLIST_SELLER_ID');
$headerToken = $_SERVER['HTTP_X_OLIST_TOKEN'] ?? null;

if (!$olistToken || $headerToken !== hash('sha256', $olistToken)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

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
