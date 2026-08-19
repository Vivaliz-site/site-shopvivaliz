<?php

declare(strict_types=1);

// Rodada 8 (2026-08-19): este arquivo passa $_REQUEST direto pro
// GitHubPullUpdateAgent (gatilho de git pull/deploy). So nao executava
// porque app/GitHubPullUpdateAgent.php nao esta versionado -- mesmo padrao
// de R7-2 (protecao por acidente, nao por controle). Defesa em profundidade
// aqui + agents/ adicionado a deny-list do .htaccess na mesma rodada. Ver
// R8-3 no relatorio da Rodada 8.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
foreach ([$root . '/app/Support.php', $root . '/vendor/autoload.php'] as $file) {
    if (is_file($file)) require_once $file;
}

require_once $root . '/app/GitHubPullUpdateAgent.php';

try {
    $agent = new ShopvivalizGitHubPullUpdateAgent();
    echo json_encode($agent->run($_REQUEST), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'sync_runner', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
