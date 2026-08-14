<?php
declare(strict_types=1);

/**
 * Autenticacao por segredo compartilhado para webhooks de parceiros que nao
 * assinam suas requisicoes.
 *
 * Tiny/Olist nao assinam os webhooks (confirmado na documentacao oficial),
 * mas permitem configurar livremente a URL de notificacao no painel. A defesa
 * viavel e um segredo na propria URL:
 *
 *     https://shopvivaliz.com.br/api/olist/webhook.php?key=SEGREDO
 *
 * Sem isso, os endpoints ficam abertos: o de estoque grava saldo arbitrario e
 * o da Olist executa DELETE FROM products.
 *
 * ROLLOUT EM DUAS ETAPAS (evita quebrar a integracao no deploy):
 *   1. Deploy com o segredo AINDA nao definido -> modo permissivo, apenas
 *      registra em log que a requisicao chegou sem chave.
 *   2. Defina OLIST_WEBHOOK_SECRET no ambiente e atualize a URL nos paineis
 *      Tiny/Olist -> a partir dai a validacao passa a ser obrigatoria.
 *
 * Operacoes destrutivas (exclusao de produto) exigem chave valida SEMPRE,
 * mesmo na etapa 1.
 */

function sv_webhook_secret_expected(): string
{
    foreach (['OLIST_WEBHOOK_SECRET', 'TINY_WEBHOOK_SECRET', 'SHOPVIVALIZ_AGENT_KEY'] as $name) {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return '';
}

function sv_webhook_secret_provided(): string
{
    foreach (['key', 'secret', 'token', 'agent_key'] as $param) {
        $value = $_GET[$param] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    $header = $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? $_SERVER['HTTP_X_AGENT_KEY'] ?? '';
    return is_string($header) ? trim($header) : '';
}

/** A requisicao apresentou o segredo correto? */
function sv_webhook_secret_valid(): bool
{
    $expected = sv_webhook_secret_expected();
    if ($expected === '') {
        return false;
    }
    return hash_equals($expected, sv_webhook_secret_provided());
}

/**
 * Porta de entrada dos webhooks nao assinados.
 *
 * @param bool $destructive true para eventos que apagam dados; nesse caso a
 *                          chave e obrigatoria mesmo antes do rollout.
 * @return bool true se o processamento pode continuar.
 */
function sv_webhook_secret_gate(string $provider, bool $destructive = false): bool
{
    if (sv_webhook_secret_valid()) {
        return true;
    }

    $expected = sv_webhook_secret_expected();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');

    if ($expected === '' && !$destructive) {
        error_log(sprintf(
            '[webhook-secret] %s aceito SEM chave (segredo nao configurado). '
            . 'Defina OLIST_WEBHOOK_SECRET e atualize a URL no painel. ip=%s',
            $provider,
            $ip
        ));
        return true;
    }

    error_log(sprintf(
        '[webhook-secret] %s REJEITADO: chave ausente ou invalida (destrutivo=%s) ip=%s',
        $provider,
        $destructive ? 'sim' : 'nao',
        $ip
    ));
    return false;
}
