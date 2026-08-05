<?php

declare(strict_types=1);

/**
 * Script de fila automática em lote — roda via CLI no Ubuntu (cron ou
 * disparado manualmente) OU chamado em segundo plano pelo admin_catalog.php
 * (ver bloco de disparo assíncrono no fim deste arquivo).
 *
 * Busca os próximos N produtos que AINDA NÃO foram otimizados para um canal
 * específico (zero linhas em catalog_optimizations_staging para aquele
 * product_id + channel), executa ai_catalog_process_item() em loop
 * controlado, e imprime logs de progresso linha a linha (flush imediato,
 * sem acumular buffer) — evitando estouro de memória do PHP em lotes
 * grandes ao liberar variáveis intermediárias a cada iteração.
 *
 * Uso via linha de comando:
 *   php cron_bulk_optimize.php --channel=ml --provider=openai --limit=20
 *
 * Aceita também a forma posicional (compatibilidade com cron simples):
 *   php cron_bulk_optimize.php ml openai 20
 *
 * Código de saída: 0 se todos os itens do lote tiveram sucesso, 1 se pelo
 * menos um item falhou (útil para alertas de cron), 2 se nem chegou a
 * processar nenhum item (erro de parâmetro/conexão).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script só pode ser executado via linha de comando (CLI).\n";
    exit;
}

require_once __DIR__ . '/config_optimization.php';
require_once __DIR__ . '/api/optimize_catalog.php';

/**
 * Parseia argumentos --chave=valor e posicionais numa única passada.
 *
 * @param array<int,string> $argv
 * @return array{channel:string, provider:string, limit:int}
 */
function ai_catalog_cli_parse_args(array $argv): array
{
    $named = [];
    $positional = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $named[$key] = $value;
        } else {
            $positional[] = $arg;
        }
    }

    $channel = $named['channel'] ?? ($positional[0] ?? '');
    $provider = $named['provider'] ?? ($positional[1] ?? '');
    $limit = (int) ($named['limit'] ?? ($positional[2] ?? 10));

    return [
        'channel' => strtolower(trim($channel)),
        'provider' => strtolower(trim($provider)),
        'limit' => max(1, min(200, $limit)),
    ];
}

function ai_catalog_cli_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

$args = ai_catalog_cli_parse_args($argv);

if ($args['channel'] === '' || !array_key_exists($args['channel'], catalog_ai_channels())) {
    fwrite(STDERR, "Canal inválido ou ausente. Uso: php cron_bulk_optimize.php --channel=ml --provider=openai --limit=20\n");
    fwrite(STDERR, 'Canais válidos: ' . implode(', ', array_keys(catalog_ai_channels())) . "\n");
    exit(2);
}

if (!in_array($args['provider'], ['openai', 'gemini', 'claude'], true)) {
    fwrite(STDERR, "Provider inválido ou ausente. Use 'openai', 'gemini' ou 'claude'.\n");
    exit(2);
}

$db = catalog_ai_db();
if ($db === null) {
    fwrite(STDERR, "Falha ao conectar ao banco de dados.\n");
    exit(2);
}

ai_catalog_cli_log("Iniciando lote: canal={$args['channel']} provider={$args['provider']} limite={$args['limit']}");

// Busca produtos que ainda não têm NENHUM registro em
// catalog_optimizations_staging para este canal específico (permite rodar
// o mesmo produto de novo para um canal DIFERENTE sem duplicar controle).
$stmt = $db->prepare(
    'SELECT p.id
     FROM produtos p
     LEFT JOIN catalog_optimizations_staging s
        ON s.product_id = p.id AND s.channel = ?
     WHERE s.id IS NULL
     ORDER BY p.id ASC
     LIMIT ' . (int) $args['limit']
);
$stmt->execute([$args['channel']]);
$productIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
$stmt = null; // libera o result set antes do loop

$total = count($productIds);
if ($total === 0) {
    ai_catalog_cli_log('Nenhum produto pendente encontrado para este canal — lote vazio, nada a fazer.');
    exit(0);
}

ai_catalog_cli_log("Encontrados $total produto(s) pendentes. Processando um a um...");

$successCount = 0;
$errorCount = 0;

foreach ($productIds as $index => $productId) {
    $position = $index + 1;
    ai_catalog_cli_log("[$position/$total] Produto #$productId — chamando {$args['provider']}...");

    $result = ai_catalog_process_item($db, $productId, $args['channel'], $args['provider']);

    if ($result['success']) {
        $successCount++;
        ai_catalog_cli_log("[$position/$total] Produto #$productId OK — staging_id={$result['staging_id']}");
    } else {
        $errorCount++;
        $errorMessage = $result['error'] ?? 'erro desconhecido';
        ai_catalog_cli_log("[$position/$total] Produto #$productId FALHOU — $errorMessage");
    }

    // Libera referências do item processado antes de seguir pro próximo —
    // evita acumular arrays grandes (prompts, respostas de IA) na memória
    // em lotes de dezenas/centenas de produtos.
    unset($result);

    // Pequena pausa entre chamadas para não estourar rate limit dos
    // provedores de IA quando o lote é grande.
    if ($position < $total) {
        usleep(300000); // 300ms
    }
}

ai_catalog_cli_log("Lote concluído: $successCount sucesso(s), $errorCount falha(s), $total total.");

exit($errorCount > 0 ? 1 : 0);
