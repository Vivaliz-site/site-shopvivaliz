<?php
/**
 * Integração ViaCEP - Busca de Endereço por CEP
 * API: https://viacep.com.br/
 */

function buscar_endereco_por_cep(string $cep): ?array
{
    // Limpar CEP (remover caracteres especiais)
    $cep = preg_replace('/[^0-9]/', '', $cep);

    // Validar formato
    if (strlen($cep) !== 8) {
        return null;
    }

    try {
        $url = "https://viacep.com.br/ws/$cep/json/";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (!isset($data['cep'])) {
            return null;
        }

        // Retornar dados normalizados
        return [
            'cep' => $data['cep'] ?? '',
            'rua' => $data['logradouro'] ?? '',
            'bairro' => $data['bairro'] ?? '',
            'cidade' => $data['localidade'] ?? '',
            'estado' => $data['uf'] ?? '',
            'erro' => isset($data['erro']) ? true : false,
        ];

    } catch (Exception $e) {
        return null;
    }
}

function formatar_cep(string $cep): string
{
    $cep = preg_replace('/[^0-9]/', '', $cep);
    return strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $cep;
}
?>
