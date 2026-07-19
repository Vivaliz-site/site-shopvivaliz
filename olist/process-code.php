<?php
/**
 * Process OAuth Code - Recebe código via GET e sincroniza
 * URL: https://shopvivaliz.com.br/olist/process-code.php?code=XXX
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$code = $_GET['code'] ?? $_POST['code'] ?? null;

if (!$code) {
    http_response_code(400);
    echo json_encode(['erro' => 'Código não fornecido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Salvar código
$code_dir = __DIR__ . '/../.tokens';
@mkdir($code_dir, 0777, true);
$code_file = $code_dir . '/olist-oauth-code.txt';
file_put_contents($code_file, $code);

error_log("[Process Code] Código recebido e salvo");

// Agora chamar complete-oauth-flow.php para fazer o resto
include __DIR__ . '/complete-oauth-flow.php';
?>
