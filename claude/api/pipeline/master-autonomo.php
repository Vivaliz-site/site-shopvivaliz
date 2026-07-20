<?php
/**
 * SHOPVIVALIZ - MASTER SCRIPT AUTÔNOMO
 * Analisa o catálogo real (tabela `products`) e reporta estatísticas e
 * recomendações derivadas dos dados reais -- sem inventar produtos,
 * métricas de mercado ou de aprendizado que não existem de fato.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('max_execution_time', '300');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$inicio = microtime(true);
$resultado = [
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'iniciando',
    'etapas' => []
];

try {
    $db = Database::getInstance()->getConnection();

    // ========================================
    // ETAPA 1: ESTATÍSTICAS REAIS DO CATÁLOGO
    // ========================================
    $resultado['etapas']['coleta_catalogo'] = 'executando';

    $totalRow = $db->query('SELECT COUNT(*) as total FROM products')->fetch_assoc();
    $totalProdutos = (int)($totalRow['total'] ?? 0);

    $precoRow = $db->query('SELECT MIN(price) as minimo, MAX(price) as maximo, AVG(price) as medio FROM products WHERE price > 0')->fetch_assoc();

    $categoriaResult = $db->query('SELECT category, COUNT(*) as qtd FROM products GROUP BY category ORDER BY qtd DESC LIMIT 1');
    $categoriaDestaque = $categoriaResult ? ($categoriaResult->fetch_assoc()['category'] ?? null) : null;

    $dados_catalogo = [
        'timestamp' => date('Y-m-d H:i:s'),
        'total_produtos' => $totalProdutos,
        'preco_minimo' => round((float)($precoRow['minimo'] ?? 0), 2),
        'preco_maximo' => round((float)($precoRow['maximo'] ?? 0), 2),
        'preco_medio' => round((float)($precoRow['medio'] ?? 0), 2),
        'categoria_destaque' => $categoriaDestaque,
    ];

    $resultado['etapas']['coleta_catalogo'] = 'concluido';
    $resultado['catalogo'] = $dados_catalogo;

    // ========================================
    // ETAPA 2: PRODUTOS SEM PREÇO OU ESTOQUE
    // ========================================
    $resultado['etapas']['auditoria_catalogo'] = 'processando';

    $semPrecoRow = $db->query('SELECT COUNT(*) as total FROM products WHERE price IS NULL OR price <= 0')->fetch_assoc();
    $semEstoqueRow = $db->query('SELECT COUNT(*) as total FROM products WHERE stock IS NULL OR stock <= 0')->fetch_assoc();

    $resultado['auditoria'] = [
        'produtos_sem_preco' => (int)($semPrecoRow['total'] ?? 0),
        'produtos_sem_estoque' => (int)($semEstoqueRow['total'] ?? 0),
    ];

    $resultado['etapas']['auditoria_catalogo'] = 'concluido';

    // ========================================
    // ETAPA 3: RECOMENDAÇÕES (derivadas dos dados acima, não inventadas)
    // ========================================
    $resultado['etapas']['recomendacoes'] = 'analisando';

    $recomendacoes = [];
    if ($resultado['auditoria']['produtos_sem_preco'] > 0) {
        $recomendacoes[] = "Corrigir {$resultado['auditoria']['produtos_sem_preco']} produto(s) sem preço válido";
    }
    if ($resultado['auditoria']['produtos_sem_estoque'] > 0) {
        $recomendacoes[] = "Repor ou desativar {$resultado['auditoria']['produtos_sem_estoque']} produto(s) sem estoque";
    }
    if ($categoriaDestaque) {
        $recomendacoes[] = "Categoria com mais produtos no catálogo: {$categoriaDestaque}";
    }
    if (empty($recomendacoes)) {
        $recomendacoes[] = 'Nenhuma inconsistência encontrada no catálogo nesta execução';
    }

    $resultado['recomendacoes'] = $recomendacoes;
    $resultado['etapas']['recomendacoes'] = 'concluido';

    // ========================================
    // RESUMO FINAL
    // ========================================
    $resultado['status'] = 'sucesso';
    $resultado['tempo_execucao'] = round(microtime(true) - $inicio, 3) . 's';
    $resultado['produtos_processados'] = $totalProdutos;

} catch (Throwable $e) {
    $resultado['status'] = 'erro';
    $resultado['erro'] = $e->getMessage();
    http_response_code(500);
}

// ========================================
// LOGGING
// ========================================
$log_file = __DIR__ . '/../../logs/master-autonomo.log';
$log_entry = sprintf(
    "[%s] MASTER AUTONOMO: %s | Produtos: %d | Tempo: %s\n",
    date('Y-m-d H:i:s'),
    $resultado['status'],
    $resultado['produtos_processados'] ?? 0,
    $resultado['tempo_execucao'] ?? 'N/A'
);

@file_put_contents($log_file, $log_entry, FILE_APPEND);

// ========================================
// RESPOSTA
// ========================================
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
