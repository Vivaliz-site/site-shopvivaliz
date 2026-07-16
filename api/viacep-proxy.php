<?php
/**
 * Proxy ViaCEP - Evita bloqueio CORS
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$cep = preg_replace('/\D/', '', $_GET['cep'] ?? '');

if (strlen($cep) !== 8) {
    http_response_code(400);
    echo json_encode(['erro' => true, 'mensagem' => 'CEP inválido']);
    exit;
}

$url = "https://viacep.com.br/ws/$cep/json/";

// Tentar com curl primeiro (preferred em produção)
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo $response;
    } else {
        http_response_code(500);
        echo json_encode(['erro' => true, 'mensagem' => 'Erro ao conectar ViaCEP']);
    }
} else {
    // Fallback: usar file_get_contents (funciona em qualquer lugar)
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response !== false) {
        // Verificar se é um JSON válido
        $data = json_decode($response, true);
        if ($data && !isset($data['erro'])) {
            echo $response;
        } else {
            http_response_code(404);
            echo json_encode(['erro' => true, 'mensagem' => 'CEP não encontrado']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['erro' => true, 'mensagem' => 'Erro ao conectar ViaCEP']);
    }
}
