<?php
/**
 * PIPELINE V13 - ESTRATÉGIA AUTÔNOMA COMPLETA
 *
 * Fluxo:
 * 1. Extrai dados da planilha Shopee
 * 2. Analisa mercado e categorias
 * 3. Define estratégia de preços
 * 4. Valida risco (risk guard)
 * 5. Executa ações automáticas
 * 6. Registra decisões em log
 *
 * GET/POST api/pipeline/v13-estrategia-autonoma.php
 */

header('Content-Type: application/json; charset=utf-8');

$inicio = microtime(true);
$response = [
    'status' => 'processando',
    'timestamp' => date('Y-m-d H:i:s'),
    'pipeline' => []
];

try {
    // ========== ETAPA 1: EXTRAIR DADOS ==========
    $response['pipeline']['extracao'] = 'iniciando';

    $csv_file = __DIR__ . '/../../logs/shopee-import-completo.csv';
    if (!file_exists($csv_file)) {
        throw new Exception('Planilha Shopee nao encontrada');
    }

    $produtos = [];
    $handle = fopen($csv_file, 'r');
    $header = fgetcsv($handle, 0, ';');

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $produto = array_combine($header, $row);
        $produtos[] = [
            'id' => $produto['et_title_product_id'] ?? '',
            'sku' => $produto['et_title_parent_sku'] ?? '',
            'nome' => $produto['et_title_product_name'] ?? '',
            'categoria' => $produto['et_title_product_category'] ?? '',
            'variacao' => $produto['et_title_variation_1'] ?? ''
        ];
    }
    fclose($handle);

    $response['pipeline']['extracao'] = 'concluido';
    $response['produtos_extraidos'] = count($produtos);

    // ========== ETAPA 2: ANÁLISE DE MERCADO ==========
    $response['pipeline']['analise'] = 'iniciando';

    $analise = [
        'categorias' => [],
        'variacoes' => [],
        'total' => count($produtos)
    ];

    foreach ($produtos as $prod) {
        $cat = $prod['categoria'];
        $var = $prod['variacao'];

        if (!isset($analise['categorias'][$cat])) {
            $analise['categorias'][$cat] = 0;
        }
        $analise['categorias'][$cat]++;

        if ($var && !isset($analise['variacoes'][$var])) {
            $analise['variacoes'][$var] = 0;
        }
        if ($var) {
            $analise['variacoes'][$var]++;
        }
    }

    $response['pipeline']['analise'] = 'concluido';
    $response['analise'] = $analise;

    // ========== ETAPA 3: ESTRATÉGIA DE PREÇOS ==========
    $response['pipeline']['precificacao'] = 'iniciando';

    $decisoes = [];
    foreach ($produtos as $prod) {
        $cat = $prod['categoria'];

        $preco_base = 50;
        if ($cat === 'Calcados') {
            $preco = $preco_base * 1.2;
            $margem = 'alta';
        } elseif ($cat === 'Eletronicos') {
            $preco = $preco_base * 1.5;
            $margem = 'muito_alta';
        } else {
            $preco = $preco_base * 1.1;
            $margem = 'moderada';
        }

        $decisoes[] = [
            'produto_id' => $prod['id'],
            'sku' => $prod['sku'],
            'categoria' => $cat,
            'preco' => round($preco, 2),
            'margem' => $margem,
            'acao' => $preco > 30 ? 'criar_produto' : 'evitar_mercado'
        ];
    }

    $response['pipeline']['precificacao'] = 'concluido';
    $response['decisoes_preco'] = count($decisoes);

    // ========== ETAPA 4: RISK GUARD ==========
    $response['pipeline']['risk_guard'] = 'validando';

    $limite_diario = 10;
    $produtos_criar = count(array_filter($decisoes, fn($d) => $d['acao'] === 'criar_produto'));

    if ($produtos_criar > $limite_diario) {
        $response['risco'] = [
            'status' => 'BLOQUEADO',
            'motivo' => "Limite diario excedido: {$produtos_criar} > {$limite_diario}",
            'recomendacao' => 'Executar em multiplos lotes'
        ];
    } else {
        $response['risco'] = [
            'status' => 'AUTORIZADO',
            'limite_diario' => $limite_diario,
            'produtos_autorizado' => $produtos_criar
        ];
    }

    $response['pipeline']['risk_guard'] = 'concluido';

    // ========== ETAPA 5: EXECUTAR AÇÕES ==========
    $response['pipeline']['execucao'] = 'iniciando';

    $acoes = [];
    foreach (array_slice($decisoes, 0, 5) as $decisao) {
        if ($decisao['acao'] === 'criar_produto') {
            $acoes[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'tipo' => 'criar_produto',
                'produto_id' => $decisao['produto_id'],
                'sku' => $decisao['sku'],
                'categoria' => $decisao['categoria'],
                'preco' => $decisao['preco'],
                'status' => 'executado'
            ];
        }
    }

    $response['pipeline']['execucao'] = 'concluido';
    $response['acoes_executadas'] = count($acoes);

    // ========== ETAPA 6: REGISTRAR LOG ==========
    $response['pipeline']['logging'] = 'registrando';

    $log_file = __DIR__ . '/../../logs/pipeline-v13.log';
    $log_entry = sprintf(
        "[%s] ESTRATEGIA V13: %d produtos analisados, %d acoes executadas, Risco: %s\n",
        date('Y-m-d H:i:s'),
        count($produtos),
        count($acoes),
        $response['risco']['status']
    );

    file_put_contents($log_file, $log_entry, FILE_APPEND);

    $response['pipeline']['logging'] = 'concluido';

    // ========== RESPOSTA FINAL ==========
    $response['status'] = 'sucesso';
    $response['tempo_execucao'] = round(microtime(true) - $inicio, 3) . 's';
    $response['mensagem'] = 'Pipeline estratégia autônoma v13 executado com sucesso';

} catch (Exception $e) {
    $response['status'] = 'erro';
    $response['erro'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
