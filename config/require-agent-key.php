<?php
declare(strict_types=1);

/**
 * Guard de autenticacao para endpoints operacionais expostos via HTTP.
 *
 * Contexto: varios scripts de manutencao ficam dentro de api/ e, por isso,
 * sao alcancaveis pelo navegador — mesmo tendo sido escritos para rodar por
 * CLI. Sem guard, qualquer pessoa na internet consegue disparar operacoes que
 * alteram o catalogo, reescrevem tokens ou mexem em arquivos do servidor.
 *
 * Uso, na primeira linha executavel do endpoint:
 *
 *     require_once __DIR__ . '/../../config/require-agent-key.php';
 *     sv_require_agent_key();
 *
 * A chamada e permitida quando:
 *   - a execucao e por CLI (php script.php), ou
 *   - o request traz a chave correta em X-Agent-Key, Authorization: Bearer
 *     ou ?agent_key=
 *
 * Se SHOPVIVALIZ_AGENT_KEY nao estiver configurada, responde 503 em vez de
 * liberar o acesso: falhar fechado e melhor do que ficar aberto por descuido.
 */

require_once __DIR__ . '/agent-keys.php';

if (!function_exists('sv_agent_key_from_request')) {
    /**
     * Le a chave enviada pelo cliente.
     *
     * Apache/PHP-FPM sem CGIPassAuth nao repassa Authorization para $_SERVER —
     * comportamento ja documentado em api/webhooks/order-status-update.php —
     * por isso o fallback via getallheaders().
     */
    function sv_agent_key_from_request(): string
    {
        $direct = trim((string)($_SERVER['HTTP_X_AGENT_KEY'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($auth === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'X-Agent-Key') === 0) {
                    return trim((string)$value);
                }
                if (strcasecmp($name, 'Authorization') === 0) {
                    $auth = trim((string)$value);
                }
            }
        }
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        return trim((string)($_GET['agent_key'] ?? ''));
    }
}

if (!function_exists('sv_require_cli')) {
    /**
     * Para scripts que so fazem sentido por linha de comando (usam $argv/$argc).
     * Expor por HTTP nao traz beneficio nenhum e so amplia a superficie de ataque.
     */
    function sv_require_cli(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        http_response_code(403);
        error_log('[agent-guard] acesso HTTP negado a script CLI: '
            . ($_SERVER['SCRIPT_NAME'] ?? '?') . ' de ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        echo json_encode(['ok' => false, 'error' => 'cli_only']);
        exit;
    }
}

if (!function_exists('sv_require_agent_key')) {
    function sv_require_agent_key(): void
    {
        // Execucao por linha de comando continua livre: e como estes scripts
        // sao chamados por cron e pelos agentes no servidor.
        if (PHP_SAPI === 'cli') {
            return;
        }

        $expected = '';
        foreach (['SHOPVIVALIZ_AGENT_KEY', 'RUNTIME_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY'] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                $expected = trim($value);
                break;
            }
        }

        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        if ($expected === '') {
            http_response_code(503);
            error_log('[agent-guard] SHOPVIVALIZ_AGENT_KEY nao configurada; endpoint bloqueado: '
                . ($_SERVER['SCRIPT_NAME'] ?? '?'));
            echo json_encode(['ok' => false, 'error' => 'agent_key_not_configured']);
            exit;
        }

        if (!hash_equals($expected, sv_agent_key_from_request())) {
            http_response_code(401);
            error_log('[agent-guard] chave invalida em ' . ($_SERVER['SCRIPT_NAME'] ?? '?')
                . ' de ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            exit;
        }
    }
}
