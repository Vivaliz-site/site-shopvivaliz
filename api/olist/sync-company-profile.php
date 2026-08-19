<?php
/**
 * API: Sincronizar dados da empresa com Olist
 * Endpoint: POST /api/olist/sync-company-profile.php
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Rodada 8 (2026-08-19): este endpoint nao tinha NENHUMA autenticacao. O GET
// publicava o cadastro fiscal completo da empresa (CNPJ, inscricao estadual/
// municipal, CNAE, regime tributario -- nada disso aparece em nenhuma pagina
// publica do site) e o POST reescrevia config/company-profile.php (consumido
// por 20 arquivos, incluindo footer/navbar/checkout e o endereco de ORIGEM
// da etiqueta Melhor Envio) com so 4 campos obrigatorios, todos publicos no
// proprio GET -- confirmado com POST {} que o 400 e de validacao de campos,
// nao de auth. O caminho legitimo e autenticado pra essa mesma operacao ja
// existe (api/webhooks/olist-company-sync.php); este arquivo parece um
// prototipo esquecido. Ver R8-1 no relatorio da Rodada 8.
require_once dirname(__DIR__, 2) . '/config/require-agent-key.php';
sv_require_agent_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'code'   => 'METHOD_NOT_ALLOWED',
        'message' => 'Method ' . $method . ' not allowed',
    ]);
    exit;
}

// Carregar configuração da empresa
$companyConfig = require dirname(__DIR__, 2) . '/config/company-profile.php';

if ($method === 'GET') {
    // Retornar dados atuais da empresa
    http_response_code(200);
    echo json_encode([
        'status'  => 'ok',
        'company' => $companyConfig,
        'synced_at' => $companyConfig['last_sync'] ?? 'nunca',
    ]);
    exit;
}

if ($method === 'POST') {
    // Receber dados do Olist e atualizar configuração
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // Validar campos obrigatórios
    $required = ['legal_name', 'fantasy_name', 'cnpj', 'email'];
    $missing = array_filter($required, fn($field) => empty($input[$field]));

    if ($missing) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'code'   => 'MISSING_FIELDS',
            'fields' => array_keys($missing),
        ]);
        exit;
    }

    // Atualizar dados
    $updated = array_merge($companyConfig, $input);
    $updated['last_sync'] = date('c');

    // Salvar em cache/BD (exemplo: arquivo de config ou BD)
    $configPath = dirname(__DIR__, 2) . '/config/company-profile.php';
    $configContent = "<?php\n/**\n * Configuração de Dados da Empresa - ShopVivaliz\n * Sincronizado com Olist Admin\n * Última atualização: " . date('Y-m-d H:i:s') . "\n */\n\ndeclare(strict_types=1);\n\nreturn " . var_export($updated, true) . ";\n";

    if (file_put_contents($configPath, $configContent)) {
        http_response_code(200);
        echo json_encode([
            'status'   => 'ok',
            'message'  => 'Perfil da empresa atualizado com sucesso',
            'synced_at' => $updated['last_sync'],
            'company'  => $updated,
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'code'    => 'SAVE_FAILED',
            'message' => 'Falha ao salvar configuração',
        ]);
    }
    exit;
}
