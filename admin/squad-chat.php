<?php
/**
 * Squad Chat — versão autenticada.
 * Mantém SQUAD_TOKEN exclusivamente no servidor e usa proxy protegido por CSRF.
 * Acesse /admin/squad-chat.php em vez de /admin/squad-chat.html.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/admin-guard.php';
require_once dirname(__DIR__) . '/config/bootstrap-env.php';

$serverToken = getenv('SQUAD_TOKEN') ?: '';

if (!isset($_SESSION['squad_chat_csrf']) || !is_string($_SESSION['squad_chat_csrf']) || strlen($_SESSION['squad_chat_csrf']) < 32) {
    $_SESSION['squad_chat_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['squad_chat_csrf'];

if (($_GET['api'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $receivedCsrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($receivedCsrf === '' || !hash_equals($csrfToken, $receivedCsrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf_invalid'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($serverToken === '') {
        http_response_code(503);
        echo json_encode(['error' => 'squad_token_unavailable'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rawBody = file_get_contents('php://input') ?: '';
    if (strlen($rawBody) > 500000) {
        http_response_code(413);
        echo json_encode(['error' => 'payload_too_large'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $ch = curl_init('https://shopvivaliz.com.br/claude/api/agent/squad-chat.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Squad-Token: ' . $serverToken,
            'User-Agent: ShopVivaliz-Admin-Squad-Proxy/1.0',
        ],
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        http_response_code(502);
        echo json_encode(['error' => 'squad_proxy_unavailable'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code($status >= 100 ? $status : 502);
    echo $response;
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$html = file_get_contents(__DIR__ . '/squad-chat.html');
if ($html === false) {
    http_response_code(500);
    echo 'Erro ao carregar squad-chat.html';
    exit;
}

$csrfMarker = "const CSRF_TOKEN = '';";
if (!str_contains($html, $csrfMarker)) {
    http_response_code(500);
    echo 'Erro ao preparar segurança do Squad Chat';
    exit;
}
$csrfJs = json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$html = str_replace($csrfMarker, "const CSRF_TOKEN = {$csrfJs};", $html);

echo $html;
