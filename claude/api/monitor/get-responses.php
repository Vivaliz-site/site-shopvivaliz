<?php
/**
 * GET respostas dos agentes IA
 * Endpoint lê monitor-responses.jsonl e retorna respostas não lidas
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $responseFile = __DIR__ . '/../../logs/monitor-responses.jsonl';
    $responses = [];

    if (file_exists($responseFile)) {
        $lines = file($responseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $data = json_decode(trim($line), true);
            if ($data) {
                $responses[] = $data;
            }
        }
    }

    // Retornar últimas 10 respostas (mais recentes primeiro)
    $responses = array_reverse($responses);
    $responses = array_slice($responses, 0, 10);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => count($responses),
        'responses' => $responses
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    error_log('Get responses error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao recuperar respostas'
    ]);
}
