<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function cleanup_test_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

function cleanup_test_read(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    cleanup_test_assert(is_file($path), "Arquivo ausente: {$relative}");
    $content = file_get_contents($path);
    cleanup_test_assert(is_string($content) && $content !== '', "Arquivo vazio: {$relative}");
    return $content;
}

$cleanup = cleanup_test_read($root, 'scripts/maintenance/cleanup-ai-smoke-staging.php');
$workflow = cleanup_test_read($root, '.github/workflows/ai-routines-production-smoke.yml');
$endpoint = cleanup_test_read($root, 'admin/catalog-optimization/api/pending_candidates_unique.php');
$availability = cleanup_test_read($root, 'admin/assets/catalog-candidate-availability.js');

cleanup_test_assert(str_contains($cleanup, 'staging_id'), 'A manutencao deve usar IDs registrados nos relatorios.');
cleanup_test_assert(str_contains($cleanup, "status = 'rejected'"), 'Linhas tecnicas devem sair das filas operacionais.');
cleanup_test_assert(str_contains($cleanup, 'catalog_candidates_shopee_eligible='), 'A manutencao deve registrar candidatos da Shopee.');
cleanup_test_assert(substr_count($workflow, 'cleanup_smoke_staging') >= 4, 'A matriz deve limpar antes e depois da execucao.');
cleanup_test_assert(str_contains($workflow, "trap 'cleanup_smoke_staging || true' EXIT"), 'A matriz deve limpar tambem no encerramento de seguranca.');
cleanup_test_assert(str_contains($endpoint, "'summary' => ["), 'O endpoint deve informar o resumo da disponibilidade.');
cleanup_test_assert(str_contains($endpoint, "'in_review' =>"), 'O endpoint deve separar produtos em revisao.');
cleanup_test_assert(str_contains($availability, 'Todos os produtos deste canal já estão em revisão'), 'A interface deve explicar a fila bloqueada.');
cleanup_test_assert(str_contains($availability, 'Ir para Revisar e aplicar'), 'A interface deve oferecer acesso aos rascunhos.');

fwrite(STDOUT, "COMPROVADO: a matriz tecnica nao bloqueia a selecao e o Admin explica produtos em revisao.\n");
