<?php
header('Content-Type: application/json');

// --- Sanitização e Validação ---
$cep = preg_replace('/[^0-9]/', '', $_GET['cep'] ?? '');

if (strlen($cep) !== 8) {
    echo json_encode(['error' => 'CEP inválido.']);
    exit;
}

$response = [
    'address' => null,
    'shipping' => null,
    'error' => null,
];

// --- Busca de Endereço (ViaCEP) ---
$viacep_url = "https://viacep.com.br/ws/{$cep}/json/";
$ch = curl_init($viacep_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$address_data = curl_exec($ch);
curl_close($ch);

$address = json_decode($address_data, true);

if ($address && !isset($address['erro'])) {
    $response['address'] = $address;
} else {
    $response['error'] = 'Endereço não encontrado para o CEP informado.';
}

// --- Simulação de Cálculo de Frete ---
// Em um cenário real, aqui seria a chamada para a API dos Correios ou transportadora.

$cost = 0;
switch (substr($cep, 0, 1)) {
    case '3': $cost = 15.50; break; // MG
    case '2': $cost = 22.80; break; // RJ
    case '0': case '1': $cost = 18.90; break; // SP
    default: $cost = 35.00; break; // Outros
}

$response['shipping'] = [
    'carrier' => 'SuperFrete',
    'delivery_time' => '5 dias úteis',
    'cost' => $cost,
];

echo json_encode($response);