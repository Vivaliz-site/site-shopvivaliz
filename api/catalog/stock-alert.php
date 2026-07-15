<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';

// Rate Limiting Básico por IP
session_start();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = "rate_limit_stock_" . md5($ip);
if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'time' => time()];
}
if (time() - $_SESSION[$rateKey]['time'] > 60) {
    $_SESSION[$rateKey] = ['count' => 1, 'time' => time()];
} else {
    $_SESSION[$rateKey]['count']++;
    if ($_SESSION[$rateKey]['count'] > 5) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Muitas requisições. Tente novamente mais tarde.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sku = trim((string)($input['sku'] ?? ''));
$email = trim((string)($input['email'] ?? ''));

if ($sku === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'SKU e e-mail são obrigatórios.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'E-mail inválido.']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Gerar token de unsubscribe
    $token = bin2hex(random_bytes(32));
    
    $sql = "INSERT INTO stock_alerts (sku, email, unsubscribe_token, status) VALUES (?, ?, ?, 'pending')";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database error");
    }
    
    $stmt->bind_param('sss', $sku, $email, $token);
    
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['ok' => true, 'message' => 'Cadastrado com sucesso! Avisaremos você.']);
    } else {
        // Se der erro de duplicate key (1062)
        if ($db->errno === 1062) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'Você já está cadastrado para ser avisado sobre este produto!']);
        } else {
            throw new Exception("Execute failed");
        }
    }
    
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno ao salvar alerta.']);
}
