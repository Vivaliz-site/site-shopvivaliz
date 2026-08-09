<?php

declare(strict_types=1);

/**
 * Diagnóstico read-only (uso único, admin-guarded): confirma SE as chaves de
 * IA estão realmente visíveis ao processo PHP que serve o site — NUNCA
 * imprime o valor real de nenhuma chave, só booleans de presença, tamanho
 * (em chars) e os caminhos de arquivo .env realmente resolvidos/verificados
 * pelo bootstrap padrão do projeto (config/constants.php).
 *
 * Motivo de existir: em 2026-08-05, o workflow sync-ai-keys-to-vm.yml
 * reportou sucesso (probes HTTP 200/400 nos 3 provedores), mas o botão
 * "Reprocessar falhas técnicas" no admin de Otimização de Cadastro continua
 * falhando 44/44 com "OPENAI_API_KEY não configurada" / "CLAUDE_API_KEY não
 * configurada" — ou seja, getenv() dentro do processo real que serve
 * admin_catalog.php não está vendo as chaves, mesmo que o probe do workflow
 * (rodado via SSH, processo Python separado) tenha funcionado. Este script
 * existe para descobrir ONDE exatamente a cadeia quebra: arquivo não existe
 * no caminho esperado, arquivo existe mas está vazio/sem a linha, ou a linha
 * existe mas getenv() não está pegando por algum motivo de precedência.
 *
 * Uso: abrir uma vez, logado como admin:
 *   https://shopvivaliz.com.br/admin/catalog-optimization/diagnostico-env-ai.php
 */

require_once __DIR__ . '/../../includes/admin-guard.php';

header('Content-Type: application/json; charset=utf-8');

// Replica exatamente a mesma resolução de caminho que config/constants.php
// usa, para garantir que estamos verificando os arquivos certos.
$projectRoot = __DIR__ . '/../..';
$projectRoot = rtrim((string) realpath($projectRoot), '/');
$projectParent = dirname($projectRoot);
$deployRoot = basename($projectParent) === 'releases'
    ? dirname($projectParent)
    : $projectParent;
$envFiles = array_values(array_unique([
    $projectRoot . '/.env',
    $deployRoot . '/shared/.env',
]));

$fileReport = [];
foreach ($envFiles as $envFile) {
    $exists = is_file($envFile);
    $readable = $exists && is_readable($envFile);
    $keysFoundInFile = [];
    if ($readable) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim(trim($v), "\"'");
            if (in_array($k, ['OPENAI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'ANTHROPIC_API_KEY', 'CLAUDE_API_KEY'], true)) {
                $keysFoundInFile[$k] = ['presente_no_arquivo' => true, 'tamanho_valor' => strlen($v)];
            }
        }
    }
    $fileReport[] = [
        'caminho' => $envFile,
        'existe' => $exists,
        'legivel' => $readable,
        'mtime' => $exists ? date('Y-m-d H:i:s', (int) filemtime($envFile)) : null,
        'tamanho_bytes' => $exists ? filesize($envFile) : null,
        'chaves_ia_encontradas_no_arquivo' => $keysFoundInFile,
    ];
}

// Agora carrega o bootstrap REAL do projeto (o mesmo que admin_catalog.php
// usa) para ver o que getenv() efetivamente retorna depois de todo o
// processo de carregamento — isso é o que importa de verdade.
require_once $projectRoot . '/config/constants.php';

$resolved = [
    'OPENAI_API_KEY' => getenv('OPENAI_API_KEY'),
    'GEMINI_API_KEY' => getenv('GEMINI_API_KEY'),
    'GOOGLE_GEMINI_API_KEY' => getenv('GOOGLE_GEMINI_API_KEY'),
    'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY'),
    'CLAUDE_API_KEY' => getenv('CLAUDE_API_KEY'),
];
$resolvedBool = [];
foreach ($resolved as $k => $v) {
    $resolvedBool[$k] = [
        'presente_apos_bootstrap' => $v !== false && trim((string) $v) !== '',
        'tamanho' => $v !== false ? strlen((string) $v) : 0,
    ];
}

echo json_encode([
    'ok' => true,
    'project_root_resolvido' => $projectRoot,
    'deploy_root_resolvido' => $deployRoot,
    'arquivos_env_verificados' => $fileReport,
    'getenv_apos_bootstrap' => $resolvedBool,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
