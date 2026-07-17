<?php
/**
 * Squad Chat — versão autenticada.
 * Lê SQUAD_TOKEN da env e injeta no sessionStorage automaticamente.
 * Acesse /admin/squad-chat.php em vez de /admin/squad-chat.html.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/admin-guard.php';
require_once dirname(__DIR__) . '/config/bootstrap-env.php';

$serverToken = getenv('SQUAD_TOKEN') ?: '';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Lê o HTML estático como template
$html = file_get_contents(__DIR__ . '/squad-chat.html');
if ($html === false) {
    http_response_code(500);
    echo 'Erro ao carregar squad-chat.html';
    exit;
}

// Injeta o token no sessionStorage antes do updateCountdown
$tokenJs = json_encode($serverToken, JSON_UNESCAPED_UNICODE);
$inject  = <<<JS

// Token SQUAD_TOKEN injetado automaticamente pelo servidor (não hardcoded no arquivo)
(function(){var t={$tokenJs};if(t){sessionStorage.setItem('SQUAD_TOKEN',t);}})();

JS;

$html = str_replace(
    'setInterval(updateCountdown,1000);',
    $inject . 'setInterval(updateCountdown,1000);',
    $html
);

// Atualiza a mensagem inicial para informar que o token foi auto-carregado
if ($serverToken !== '') {
    $html = str_replace(
        "addMsg('director','Esquadrão ShopVivaliz online. GPT, Claude e Gemini estão configurados como agentes reais no backend. Informe o SQUAD_TOKEN para iniciar chamadas reais. O ciclo autônomo começa pausado para evitar custo automático.');",
        "addMsg('director','Esquadrão ShopVivaliz online. Token SQUAD_TOKEN carregado automaticamente do servidor. GPT, Claude e Gemini prontos. O ciclo autônomo começa pausado para evitar custo automático.');",
        $html
    );
}

echo $html;
