<?php

declare(strict_types=1);

// Rodada 8 (2026-08-19): script de CLI que estava respondendo a HTTP
// (agents/ nao estava na deny-list do .htaccess -- corrigido na mesma
// rodada). Defesa em profundidade: mesmo se agents/ for reaberto no futuro,
// este script nunca deve executar via navegador/curl. Ver R8-3 no relatorio
// da Rodada 8.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../app/ShopeeListingsExtractorAgent.php';

$agent  = new ShopeeListingsExtractorAgent();
$result = $agent->run();

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Saída padrão (stdout)
echo $json . PHP_EOL;

// Salva em arquivo se OUTPUT_FILE estiver definido
$outputFile = getenv('OUTPUT_FILE') ?: '';
if ($outputFile !== '') {
    $dir = dirname($outputFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($outputFile, $json);
    fwrite(STDERR, '[ShopeeListingsExtractor] Salvo em: ' . $outputFile . PHP_EOL);
}

exit(in_array($result['status'], ['success', 'partial'], true) ? 0 : 1);
