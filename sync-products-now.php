<?php
/**
 * Sincronizar Produtos do ERP Olist/Tiny V3
 * Conta quantos foram sincronizados
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Carregar .env
$envFile = dirname(__DIR__) . '/.env';
$accessToken = '';

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
            $accessToken = explode('=', $line, 2)[1] ?? '';
            break;
        }
    }
}

$accessToken = trim($accessToken);

if (!$accessToken) {
    http_response_code(400);
    echo json_encode(['erro' => 'Access token não configurado']);
    exit;
}

// ============================================================
// CONECTAR BD
// ============================================================

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=shopvivaliz;charset=utf8mb4',
        'shopvivaliz',
        'shopvivaliz123'
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao conectar BD: ' . $e->getMessage()]);
    exit;
}

// ============================================================
// BUSCAR PRODUTOS DA API V3
// ============================================================

$totalSincronizados = 0;
$offset = 0;
$limit = 100;
$pagina = 1;

echo json_encode(['status' => 'iniciando', 'timestamp' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE) . "\n";

while (true) {
    $url = "https://api.tiny.com.br/public-api/v3/produtos?limit=$limit&offset=$offset";

    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $accessToken\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if (!$response) {
        break;
    }

    $data = json_decode($response, true);

    if (!isset($data['data']) || empty($data['data'])) {
        break;
    }

    // Sincronizar cada produto
    foreach ($data['data'] as $item) {
        $id = $item['id'] ?? null;
        $nome = $item['nome'] ?? '';
        $descricao = $item['descricao'] ?? '';
        $preco = $item['preco'] ?? 0;
        $estoque = $item['estoque'] ?? 0;
        $sku = $item['sku'] ?? '';
        $ativo = $item['ativo'] ?? true;

        if (!$id) continue;

        // Inserir ou atualizar
        $sql = "
            INSERT INTO products
            (external_id, name, description, price, stock, sku, active, source, updated_at)
            VALUES
            (:id, :nome, :desc, :preco, :estoque, :sku, :ativo, 'erp_olist', NOW())
            ON DUPLICATE KEY UPDATE
                name = :nome,
                description = :desc,
                price = :preco,
                stock = :estoque,
                sku = :sku,
                active = :ativo,
                updated_at = NOW()
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':nome' => $nome,
                ':desc' => $descricao,
                ':preco' => (float)$preco,
                ':estoque' => (int)$estoque,
                ':sku' => $sku,
                ':ativo' => $ativo ? 1 : 0,
            ]);
            $totalSincronizados++;
        } catch (Exception $e) {
            // Log erro mas continua
        }
    }

    // Verificar se há mais páginas
    if (count($data['data']) < $limit) {
        break;
    }

    $offset += $limit;
    $pagina++;
}

// ============================================================
// RESULTADO
// ============================================================

$totalBd = $pdo->query("SELECT COUNT(*) FROM products WHERE source='erp_olist'")->fetchColumn();

http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => "Sincronizacao concluida!",
    'produtos_sincronizados_agora' => $totalSincronizados,
    'total_produtos_banco' => (int)$totalBd,
    'timestamp' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
