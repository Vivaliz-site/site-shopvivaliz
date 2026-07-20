<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/account-chrome.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$body = is_array($body) ? $body : [];

if (!sv_csrf_valid('account-actions', $body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$label = trim((string)($body['label'] ?? '')) ?: 'Endereço';
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? ''));
$street = trim((string)($body['street'] ?? ''));
$number = trim((string)($body['number'] ?? ''));
$complement = trim((string)($body['complement'] ?? ''));
$neighborhood = trim((string)($body['neighborhood'] ?? ''));
$city = trim((string)($body['city'] ?? ''));
$state = strtoupper(trim((string)($body['state'] ?? '')));
$isDefault = !empty($body['is_default']);

if (strlen($cep) !== 8 || $street === '' || $number === '' || $neighborhood === '' || $city === '' || strlen($state) !== 2) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_required_fields']);
    exit;
}

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    $userId = (int)$_SESSION['user_id'];

    if ($isDefault) {
        $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = :uid')->execute([':uid' => $userId]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO addresses (user_id, label, cep, street, number, complement, neighborhood, city, state, is_default)
         VALUES (:uid, :label, :cep, :street, :number, :complement, :neighborhood, :city, :state, :is_default)'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':label' => substr($label, 0, 60),
        ':cep' => substr($cep, 0, 9),
        ':street' => substr($street, 0, 255),
        ':number' => substr($number, 0, 20),
        ':complement' => $complement !== '' ? substr($complement, 0, 120) : null,
        ':neighborhood' => substr($neighborhood, 0, 120),
        ':city' => substr($city, 0, 120),
        ':state' => $state,
        ':is_default' => $isDefault ? 1 : 0,
    ]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('[MinhaConta] address-save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
