<?php
/**
 * Proxy ViaCEP - Evita bloqueio CORS & Implementa Cache em Banco SQLite Local
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
// Rodada 8 (2026-08-19): Access-Control-Allow-Origin: * transformava a loja
// num proxy ViaCEP gratuito pra qualquer site de terceiros, consumindo a
// quota/IP da propria VM -- o unico consumidor real e o checkout do proprio
// site. Ver R8-10 no relatorio da Rodada 8.
header('Access-Control-Allow-Origin: https://shopvivaliz.com.br');
header('Access-Control-Allow-Methods: GET');

// Rodada 8 (2026-08-19): sem rate limit, um varredor podia enumerar o
// espaco de CEPs validos livremente. Ver R8-10 no relatorio da Rodada 8.
require_once __DIR__ . '/../includes/order-rate-limit.php';
if (!svorl_allow(60, 3600, 'viacep')) {
    http_response_code(429);
    echo json_encode(['erro' => true, 'mensagem' => 'Muitas requisições, tente novamente em instantes.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cep = preg_replace('/\D/', '', $_GET['cep'] ?? '');

if (strlen($cep) !== 8) {
    http_response_code(400);
    echo json_encode(['erro' => true, 'mensagem' => 'CEP inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Inicializar base de cache SQLite local na pasta de storage/
$dbPath = __DIR__ . '/../storage/viacep_cache.db';
$db = null;

try {
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0755, true);
    }
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS cep_cache (
        cep TEXT PRIMARY KEY,
        response TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )");
    // Rodada 8 (2026-08-19): a validade de 30 dias so decidia se o cache era
    // USADO -- a linha antiga nunca era removida, entao a tabela crescia sem
    // teto (mesma familia de R7-9). GC probabilistico de baixo custo. Ver
    // R8-10 no relatorio da Rodada 8.
    if (random_int(1, 200) === 1) {
        $db->exec('DELETE FROM cep_cache WHERE created_at < ' . (time() - 60 * 86400));
    }
} catch (Throwable $e) {
    // Se o SQLite falhar, apenas prossegue sem cache para não travar a requisição
    error_log('SQLite viacep_cache initialization failed: ' . $e->getMessage());
}

// 1. Tentar buscar do Cache SQLite local (validade de 30 dias)
if ($db instanceof PDO) {
    try {
        $stmt = $db->prepare('SELECT response, created_at FROM cep_cache WHERE cep = :cep LIMIT 1');
        $stmt->execute([':cep' => $cep]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            $age = time() - (int)$cached['created_at'];
            if ($age < 30 * 86400) {
                echo $cached['response'];
                exit;
            }
        }
    } catch (Throwable $e) {
        error_log('SQLite cache read failed: ' . $e->getMessage());
    }
}

$url = "https://viacep.com.br/ws/$cep/json/";
$response = null;

// 2. Tentar com curl primeiro (preferred em produção)
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $exec = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && is_string($exec)) {
        $response = $exec;
    }
} else {
    // Fallback: usar file_get_contents
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
        ]
    ]);
    $exec = @file_get_contents($url, false, $context);
    if (is_string($exec)) {
        $response = $exec;
    }
}

if ($response !== null) {
    $data = json_decode($response, true);
    if (is_array($data) && !isset($data['erro'])) {
        // Gravar no cache local antes de responder
        if ($db instanceof PDO) {
            try {
                $stmt = $db->prepare('INSERT OR REPLACE INTO cep_cache (cep, response, created_at) VALUES (:cep, :response, :created_at)');
                $stmt->execute([
                    ':cep' => $cep,
                    ':response' => $response,
                    ':created_at' => time()
                ]);
            } catch (Throwable $e) {
                error_log('SQLite cache write failed: ' . $e->getMessage());
            }
        }
        echo $response;
        exit;
    }
}

// Fallback de erro
http_response_code(500);
echo json_encode(['erro' => true, 'mensagem' => 'Não foi possível consultar o CEP agora.'], JSON_UNESCAPED_UNICODE);
