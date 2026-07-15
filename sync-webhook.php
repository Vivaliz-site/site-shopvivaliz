<?php
/**
 * Webhook para forçar sincronização do repositório
 * Acesse: https://dev.shopvivaliz.com.br/sync-webhook.php?token=seu-token
 */

// Token simples de proteção
$ALLOWED_TOKEN = 'sync-' . substr(md5('shopvivaliz-sync-' . date('Y-m-d')), 0, 16);
$PROVIDED_TOKEN = $_GET['token'] ?? '';

if ($PROVIDED_TOKEN !== $ALLOWED_TOKEN) {
    http_response_code(403);
    die(json_encode(['error' => 'Token inválido ou não fornecido']));
}

header('Content-Type: application/json; charset=utf-8');

$repoPath = __DIR__;
$output = [];

// Fazer git fetch
$output[] = ['command' => 'git fetch origin', 'result' => shell_exec("cd $repoPath && git fetch origin 2>&1")];

// Fazer git reset --hard
$output[] = ['command' => 'git reset --hard origin/main', 'result' => shell_exec("cd $repoPath && git reset --hard origin/main 2>&1")];

// Tocar em index.php para forçar reload do Apache
$output[] = ['command' => 'touch index.php', 'result' => shell_exec("cd $repoPath && touch index.php && ls -la index.php 2>&1")];

// Limpar opcache se disponível
if (function_exists('opcache_reset')) {
    opcache_reset();
    $output[] = ['command' => 'opcache_reset()', 'result' => 'OK'];
}

echo json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'token_used' => substr($ALLOWED_TOKEN, 0, 10) . '...',
    'operations' => $output
], JSON_PRETTY_PRINT);
