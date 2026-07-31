<?php
/**
 * 🔥 Hotspot API Endpoint - Recebe tracking data
 */

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'No data']);
    exit;
}

$route = $_SERVER['REQUEST_URI'];

if (strpos($route, '/api/hotspot/track') !== false) {
    // Salvar click data
    $logFile = '.hotspot-clicks.jsonl';
    file_put_contents($logFile, json_encode($data) . "\n", FILE_APPEND);

    // Análise de checkout abandonment
    if (isset($data['form_data'])) {
        analyzeFormAbandonment($data);
    }

    echo json_encode(['success' => true]);
} elseif (strpos($route, '/api/hotspot/conversion') !== false) {
    // Salvar conversion
    $logFile = '.hotspot-conversions.jsonl';
    file_put_contents($logFile, json_encode($data) . "\n", FILE_APPEND);

    // Trigger cart recovery email se abandonment
    if (isset($data['form_data']) && empty($data['completed'])) {
        triggerCartRecoveryEmail($data);
    }

    echo json_encode(['success' => true, 'conversion_tracked' => true]);
}

function analyzeFormAbandonment($data) {
    // Analisar quais campos causam abandonment
    $fieldsCompleted = $data['form_data'] ?? [];

    // Se menos de 50% preenchido, é alto risco de abandonment
    $completionRate = !empty($fieldsCompleted) ? count(array_filter($fieldsCompleted, fn($f) => $f['filled'])) / count($fieldsCompleted) * 100 : 0;

    if ($completionRate < 50 && $completionRate > 0) {
        file_put_contents('.cart-abandonments.jsonl', json_encode([
            'session_id' => $data['session_id'],
            'completion_rate' => $completionRate,
            'url' => $data['url'],
            'timestamp' => $data['timestamp'],
        ]) . "\n", FILE_APPEND);
    }
}

function triggerCartRecoveryEmail($data) {
    // Enviar email de recuperação de carrinho abandonado
    $email_queue = json_decode(file_get_contents('.email-queue.json') ?: '[]', true);

    $email_queue[] = [
        'type' => 'cart_recovery',
        'session_id' => $data['session_id'],
        'timestamp' => date('Y-m-d H:i:s'),
        'delay_minutes' => 60, // Enviar após 1 hora
    ];

    file_put_contents('.email-queue.json', json_encode($email_queue));
}
