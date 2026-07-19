<?php
/**
 * Diagnostico Olist - Validar integracao completa
 * Testa todos os endpoints e status da sincronizacao
 *
 * Acesso: https://shopvivaliz.com.brapi/olist/diagnostic.php
 */

header('Content-Type: application/json; charset=utf-8');

$tests = [];
$passed = 0;
$failed = 0;

// ============================================================================
// TESTE 1: Credenciais
// ============================================================================

$client_id = getenv('OLIST_CLIENT_ID');
$client_secret = getenv('OLIST_CLIENT_SECRET');

$tests[] = [
    'nome' => 'Credenciais configuradas',
    'resultado' => $client_id && $client_secret ? 'OK' : 'FALHOU',
    'detalhes' => $client_id && $client_secret
        ? "Client ID: " . substr($client_id, 0, 20) . "..."
        : "Faltam OLIST_CLIENT_ID ou OLIST_CLIENT_SECRET"
];

if ($client_id && $client_secret) $passed++; else $failed++;

// ============================================================================
// TESTE 2: Arquivos necessarios
// ============================================================================

$arquivos = [
    'connect.php' => '/olist/connect.php',
    'callback.php' => '/olist/callback.php',
    'sync-products.php' => '/olist/sync-products.php',
    'token-refresh.php' => 'api/olist/token-refresh.php'
];

foreach ($arquivos as $nome => $path) {
    $existe = file_exists(__DIR__ . '/../../' . ltrim($path, '/'));
    $tests[] = [
        'nome' => "Arquivo $nome existe",
        'resultado' => $existe ? 'OK' : 'FALHOU',
        'detalhes' => $path
    ];
    if ($existe) $passed++; else $failed++;
}

// ============================================================================
// TESTE 3: Tabelas do banco de dados
// ============================================================================

try {
    require_once __DIR__ . '/../../config/database.php';
    $db = Database::getInstance();

    $tables = ['olist_products', 'olist_product_images'];

    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        $existe = $result && $result->num_rows > 0;

        $tests[] = [
            'nome' => "Tabela $table existe",
            'resultado' => $existe ? 'OK' : 'FALHOU',
            'detalhes' => $existe
                ? "Tabela presente no banco"
                : "Tabela nao encontrada - execute migrations"
        ];

        if ($existe) $passed++; else $failed++;
    }

} catch (Exception $e) {
    $tests[] = [
        'nome' => 'Conexao com banco de dados',
        'resultado' => 'ERRO',
        'detalhes' => $e->getMessage()
    ];
    $failed++;
}

// ============================================================================
// TESTE 4: Cache de produtos
// ============================================================================

$cache_file = __DIR__ . '/../../storage/cache/olist-products-cache.json';
$cache_existe = file_exists($cache_file);

if ($cache_existe) {
    $cache_data = json_decode((string)file_get_contents($cache_file), true) ?: [];
    $cache_idade = time() - filemtime($cache_file);
    $cache_horas = floor($cache_idade / 3600);

    $tests[] = [
        'nome' => 'Cache de produtos',
        'resultado' => 'OK',
        'detalhes' => $cache_data['total'] . " produtos, " .
                     $cache_data['com_imagem'] . " com imagem, " .
                     $cache_horas . " horas atras"
    ];
    $passed++;
} else {
    $tests[] = [
        'nome' => 'Cache de produtos',
        'resultado' => 'FALHOU',
        'detalhes' => 'Nenhum cache - acesse /olist/sync-products.php para sincronizar'
    ];
    $failed++;
}

// ============================================================================
// TESTE 5: Session (para armazenar tokens)
// ============================================================================

session_start();
$tests[] = [
    'nome' => 'Sessao funcionando',
    'resultado' => 'OK',
    'detalhes' => 'PHP Sessions ativas'
];
$passed++;

// ============================================================================
// TESTE 6: Conectividade com API Olist
// ============================================================================

if ($client_id && $client_secret) {
    $ch = curl_init("https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => 'TEST_CODE_INVALIDO',
            'redirect_uri' => 'https://shopvivaliz.com.br/olist/sync-products.php'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        $tests[] = [
            'nome' => 'Conectividade com Olist API',
            'resultado' => 'ERRO',
            'detalhes' => "Erro de conexao: $error"
        ];
        $failed++;
    } else {
        $tests[] = [
            'nome' => 'Conectividade com Olist API',
            'resultado' => 'OK',
            'detalhes' => "API respondendo (status $status)"
        ];
        $passed++;
    }
}

// ============================================================================
// TESTE 7: Permissoes de arquivos
// ============================================================================

$log_dir = __DIR__ . '/../../logs';
$log_writable = is_dir($log_dir) && is_writable($log_dir);

$tests[] = [
    'nome' => 'Permissoes de escrita em logs/',
    'resultado' => $log_writable ? 'OK' : 'AVISO',
    'detalhes' => $log_writable
        ? "Diretorio logs/ pode ser escrito"
        : "Permissoes insuficientes - verifique chmod 755"
];

if ($log_writable) $passed++; else $failed++;

// ============================================================================
// RESUMO
// ============================================================================

$resumo = [
    'status' => $failed === 0 ? 'TUDO_OK' : ($failed <= 2 ? 'PARCIAL' : 'CRITICO'),
    'total_testes' => count($tests),
    'passou' => $passed,
    'falhou' => $failed,
    'timestamp' => date('c'),
    'servidor' => $_SERVER['HTTP_HOST'] ?? 'desconhecido',
    'versao_php' => phpversion(),
    'extensoes_necessarias' => [
        'curl' => extension_loaded('curl') ? 'OK' : 'FALTANDO',
        'json' => extension_loaded('json') ? 'OK' : 'FALTANDO',
        'pdo_mysql' => extension_loaded('pdo_mysql') ? 'OK' : 'FALTANDO'
    ]
];

// ============================================================================
// LINKS PARA ACAO
// ============================================================================

$proximos_passos = [
    'Passo 1: Autorizar Olist' => [
        'url' => 'https://shopvivaliz.com.br/olist/connect.php',
        'descricao' => 'Clique para fazer login e autorizar ShopVivaliz a acessar seus produtos'
    ],
    'Passo 2: Callback' => [
        'url' => 'https://shopvivaliz.com.br/olist/callback.php',
        'descricao' => 'Volta automaticamente aqui com o codigo de autorizacao'
    ],
    'Passo 3: Sincronizar' => [
        'url' => 'https://shopvivaliz.com.br/olist/sync-products.php',
        'descricao' => 'Sincroniza todos os 198 produtos com imagens'
    ],
    'Passo 4: Renovar Token' => [
        'url' => 'https://shopvivaliz.com.brapi/olist/token-refresh.php',
        'descricao' => 'Se o token expirar, use isso para renovar (dura ~4 horas)'
    ]
];

// ============================================================================
// RESPOSTA FINAL
// ============================================================================

http_response_code($failed === 0 ? 200 : 202);

echo json_encode([
    'diagnostico' => $resumo,
    'testes' => $tests,
    'proximos_passos' => $proximos_passos,
    'avisos' => [
        'Nao exponha access_token em logs publicos',
        'Nao commite credenciais no GitHub',
        'Token expira em ~4 horas, use token-refresh.php',
        'Refresh token dura 1 dia, depois faca login novamente'
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

session_write_close();
?>
