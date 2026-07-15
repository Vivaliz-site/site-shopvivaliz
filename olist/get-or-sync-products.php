<?php
/**
 * Get or Sync Products - Retorna 198 produtos
 * Se cache existe, retorna do cache
 * Se não, tenta sincronizar usando refresh_token salvo
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

$cache_file = __DIR__ . '/../logs/olist-products-cache.json';
$config_file = __DIR__ . '/../.tokens/olist-config.json';

// Se cache existe e é válido, retornar
if (file_exists($cache_file)) {
    $cache_time = filemtime($cache_file);
    if (time() - $cache_time < 86400) { // 24 horas
        $data = json_decode(file_get_contents($cache_file), true);
        if ($data && !empty($data['produtos'])) {
            http_response_code(200);
            echo json_encode(['sucesso' => true, 'fonte' => 'cache', 'total' => count($data['produtos']), 'produtos' => $data['produtos']]);
            exit;
        }
    }
}

// Tentar sincronizar se tiver config salva
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    $refresh_token = $config['refresh_token'] ?? null;

    if ($refresh_token) {
        // Renovar access_token
        $ch = curl_init("https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => trim($refresh_token)
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status == 200) {
            $token_data = json_decode($response, true);

            if (isset($token_data['access_token'])) {
                $access_token = $token_data['access_token'];

                // Buscar produtos
                $todos_produtos = [];
                $pagina = 1;

                while ($pagina <= 20) {
                    $url = "https://api.tiny.com.br/api/v2/produtos.json?limite=50&pagina=$pagina&formato=json";

                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            "Authorization: Bearer $access_token"
                        ]
                    ]);

                    $response = curl_exec($ch);
                    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($status != 200) {
                        break;
                    }

                    $data = json_decode($response, true);
                    $produtos = $data['produtos'] ?? [];

                    if (count($produtos) === 0) {
                        break;
                    }

                    $todos_produtos = array_merge($todos_produtos, $produtos);

                    if (count($produtos) < 50) {
                        break;
                    }

                    $pagina++;
                }

                if (count($todos_produtos) > 0) {
                    // Salvar cache
                    $cache_data = [
                        'timestamp' => date('c'),
                        'total' => count($todos_produtos),
                        'produtos' => $todos_produtos
                    ];

                    @mkdir(dirname($cache_file), 0755, true);
                    file_put_contents($cache_file, json_encode($cache_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                    http_response_code(200);
                    echo json_encode(['sucesso' => true, 'fonte' => 'api', 'total' => count($todos_produtos), 'produtos' => $todos_produtos]);
                    exit;
                }
            }
        }
    }
}

// Fallback: retornar vazio
http_response_code(200);
echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum produto disponível. Faça login em /olist/login-form.php']);
?>
