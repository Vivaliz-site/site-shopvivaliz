<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/ShopeeListingsOptimizationAgent.php';

$agent  = new ShopeeListingsOptimizationAgent();
$result = $agent->run();

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

echo $json . PHP_EOL;

$outputFile = getenv('OUTPUT_FILE') ?: '';
if ($outputFile !== '') {
    $dir = dirname($outputFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($outputFile, $json);
    fwrite(STDERR, '[ShopeeOptimization] Relatório salvo em: ' . $outputFile . PHP_EOL);
}

$status = $result['status'] ?? 'error';
fwrite(STDERR, sprintf(
    '[ShopeeOptimization] status=%s | total=%d | otimizados=%d | erros=%d%s',
    $status,
    $result['total_products'] ?? 0,
    $result['optimized']      ?? 0,
    count($result['errors']   ?? []),
    PHP_EOL
));

exit(in_array($status, ['success', 'partial'], true) ? 0 : 1);
