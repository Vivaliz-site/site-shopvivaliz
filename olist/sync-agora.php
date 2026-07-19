<?php
/**
 * Sincronizar AGORA - Pode ser chamado via:
 * 1. URL direto: https://dev.shopvivaliz.com.br/olist/sync-agora.php
 * 2. Com parâmetros: ?refresh_token=TOKEN
 *
 * Tenta automaticamente encontrar ou renovar token
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("=== SYNC AGORA INICIADO ===");

try {
    // ========================================================================
    // PASSO 1: Obter refresh_token
    // ========================================================================

    log_msg("PASSO 1: Procurando refresh_token...");

    $refresh_token = null;

    // 1. Tentar receber como parâmetro
    if (isset($_GET['refresh_token'])) {
        $refresh_token = $_GET['refresh_token'];
        log_msg("  Token recebido via parâmetro GET");
    }

    // 2. Tentar ler de arquivo JSON
    if (!$refresh_token) {
        $token_config_file = __DIR__ . '/../.tokens/olist-config.json';
        if (file_exists($token_config_file)) {
            $config = json_decode(file_get_contents($token_config_file), true);
            $refresh_token = $config['refresh_token'] ?? null;
            if ($refresh_token) {
                log_msg("  Token encontrado em arquivo JSON");
            }
        }
    }

    // 3. Tentar ler de arquivo texto
    if (!$refresh_token) {
        $token_file = __DIR__ . '/../.tokens/olist_refresh_token.txt';
        if (file_exists($token_file)) {
            $refresh_token = trim(file_get_contents($token_file));
            if ($refresh_token) {
                log_msg("  Token encontrado em arquivo texto");
            }
        }
    }

    // 4. Tentar ler de SESSION
    if (!$refresh_token) {
        session_start();
        $refresh_token = $_SESSION['olist_refresh_token'] ?? null;
        if ($refresh_token) {
            log_msg("  Token encontrado em SESSION");
        }
    }

    if (!$refresh_token) {
        exit_error("Token não encontrado em nenhuma fonte. Faça login em /olist/setup-oauth.php");
    }

    // ========================================================================
    // PASSO 2: Renovar access_token
    // ========================================================================

    log_msg("\nPASSO 2: Renovando access_token...");

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

    if ($status != 200) {
        log_msg("  ERRO: Status $status");
        exit_error("Falha ao renovar token. Faça login novamente em /olist/setup-oauth.php");
    }

    $token_data = json_decode($response, true);

    if (!isset($token_data['access_token'])) {
        exit_error("Access token não recebido");
    }

    $access_token = $token_data['access_token'];
    log_msg("  Access token renovado!");

    // ========================================================================
    // PASSO 3: Sincronizar catalogo real via API v3 OAuth
    // ========================================================================
    // A busca antiga (API v2 legada, paginada) so gravava em
    // logs/olist-products-cache.json, um arquivo que o site nunca le -- ver
    // docs/MEMORIA-AGENTES.md. O catalogo real e api/catalog/fallback-products.json,
    // mantido por sync-products.php (v3 OAuth), que ja reaproveita o token salvo.

    log_msg("\nPASSO 3: Sincronizando catalogo via olist/sync-products.php (API v3)...");

    $sync_output = shell_exec('php ' . escapeshellarg(__DIR__ . '/sync-products.php') . ' 2>&1');
    log_msg("  Saida do sync v3: " . trim((string)$sync_output));

    // ========================================================================
    // RESULTADO
    // ========================================================================

    log_msg("\n=== SINCRONIZACAO CONCLUIDA COM SUCESSO ===");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Catalogo sincronizado via API v3.',
        'sync_output' => trim((string)$sync_output),
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-sync-agora.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[Sync Agora] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode([
        'erro' => $msg,
        'sucesso' => false,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
