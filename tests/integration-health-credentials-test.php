<?php

declare(strict_types=1);

/**
 * Teste de regressão para includes/integration-health.php::svih_olist_candidates().
 *
 * Existe por causa de um incidente real: o commit 17a6c5ef7 (PR #747,
 * 2026-08-04) fragmentou a resolução de client_id/client_secret do Tiny/
 * Olist em 3 "famílias" isoladas (olist/tiny/legacy), passando a exigir que
 * a MESMA família tivesse também o refresh_token persistido. Na conta real
 * de produção, client_id/client_secret estavam gravados sob um nome (ex:
 * legado CLIENT_ID_API_OLIST/CLIENT_SECRET_OLIST) e o refresh_token
 * persistido tinha credential_family diferente — nenhuma família batia com
 * as 4 peças completas, e o painel /admin/connections.php reportava
 * "Credenciais OAuth incompletas ou placeholders" mesmo com tudo
 * configurado. Ninguém percebeu até o dono do produto notar a integração
 * "falhando" e contestar o diagnóstico inicial (que assumiu, errado, que
 * era um bloqueio antigo e não uma regressão de código).
 *
 * Este teste simula exatamente esse cenário (client_id/secret sob nomes
 * legados + refresh_token com família diferente) e falha caso a resolução
 * de credenciais volte a exigir que tudo esteja sob a mesma família.
 *
 * Uso: php tests/integration-health-credentials-test.php
 */

function ihct_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// putenv() é honrado por svih_env() (getenv() puro) — não precisamos de
// subprocesso, mas limpamos tudo no fim pra não vazar estado entre testes
// caso este arquivo seja incluído por outro runner no futuro.
$envKeysToClean = [
    'OLIST_CLIENT_ID', 'OLIST_CLIENT_SECRET', 'OLIST_REFRESH_TOKEN', 'OLIST_ACCESS_TOKEN',
    'TINY_CLIENT_ID', 'TINY_CLIENT_SECRET', 'TINY_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN',
    'CLIENT_ID_API_OLIST', 'CLIENT_SECRET_OLIST',
];

function ihct_clear_env(array $keys): void
{
    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }
}

require_once __DIR__ . '/../includes/integration-health.php';

try {
    // --- Cenário 1 (o incidente real): client_id/secret sob nomes legados,
    // refresh_token persistido sob família "tiny" (não "legacy"). Antes da
    // correção, isso resultava em ZERO candidato completo. ---
    ihct_clear_env($envKeysToClean);
    putenv('CLIENT_ID_API_OLIST=' . str_repeat('i', 24));
    putenv('CLIENT_SECRET_OLIST=' . str_repeat('s', 24));
    $_ENV['CLIENT_ID_API_OLIST'] = getenv('CLIENT_ID_API_OLIST');
    $_ENV['CLIENT_SECRET_OLIST'] = getenv('CLIENT_SECRET_OLIST');

    $storedWithDifferentFamily = [
        'credential_family' => 'tiny',
        'OLIST_REFRESH_TOKEN' => str_repeat('a', 40), // simula um JWT longo persistido
        'OLIST_ACCESS_TOKEN' => str_repeat('b', 40),
    ];

    $candidates = svih_olist_candidates($storedWithDifferentFamily);
    $anyComplete = array_reduce(
        $candidates,
        static fn(bool $carry, array $c): bool => $carry || $c['complete'],
        false
    );

    ihct_assert(
        $anyComplete,
        'client_id/secret configurados sob nome legado + refresh_token persistido sob família diferente devem ' .
        'resolver para pelo menos 1 candidato completo (regressão do PR #747 reintroduzida)'
    );

    $best = $candidates[0];
    ihct_assert($best['complete'], 'o candidato de maior score (o escolhido pelo painel) deve ser o completo');
    ihct_assert($best['client_id'] !== '', 'client_id do candidato escolhido não pode ficar vazio');
    ihct_assert($best['client_secret'] !== '', 'client_secret do candidato escolhido não pode ficar vazio');
    ihct_assert($best['refresh_token'] !== '', 'refresh_token do candidato escolhido não pode ficar vazio');

    // --- Cenário 2 (negativo): nada configurado em lugar nenhum. Garante
    // que não "quebramos ao contrário" fazendo tudo parecer completo
    // sempre, o que mascararia uma falta de credencial real. ---
    ihct_clear_env($envKeysToClean);
    $candidatesEmpty = svih_olist_candidates([]);
    $noneComplete = array_reduce(
        $candidatesEmpty,
        static fn(bool $carry, array $c): bool => $carry && !$c['complete'],
        true
    );
    ihct_assert($noneComplete, 'sem nenhuma credencial configurada, nenhum candidato deve aparecer como completo');

    // --- Cenário 3: client_id/secret sob o nome "canônico" OLIST_* também
    // deve continuar funcionando (não regredir o caminho feliz simples). ---
    ihct_clear_env($envKeysToClean);
    putenv('OLIST_CLIENT_ID=' . str_repeat('c', 20));
    putenv('OLIST_CLIENT_SECRET=' . str_repeat('d', 20));
    putenv('OLIST_REFRESH_TOKEN=' . str_repeat('e', 20));
    $candidatesCanonical = svih_olist_candidates([]);
    $anyCompleteCanonical = array_reduce(
        $candidatesCanonical,
        static fn(bool $carry, array $c): bool => $carry || $c['complete'],
        false
    );
    ihct_assert($anyCompleteCanonical, 'credenciais sob os nomes canônicos OLIST_* devem continuar resolvendo normalmente');

    echo "OK: resolução de credenciais Tiny/Olist (client_id+secret+refresh_token)\n";
} finally {
    ihct_clear_env($envKeysToClean);
}
