<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dedupe = file_get_contents($root . '/js/shipping-request-dedupe-v1.js');
$privacy = file_get_contents($root . '/js/privacy-consent-v1.js');

if ($dedupe === false || $privacy === false) {
    fwrite(STDERR, "FAIL: assets de deduplicacao nao podem ser lidos\n");
    exit(1);
}

$dedupeChecks = [
    "/api/melhorenvio/shipping-check-v2.php" => 'wrapper nao esta restrito ao endpoint de frete',
    "var inFlightKey = '';" => 'estado de requisicao em andamento ausente',
    'inFlightPromise && inFlightKey === key' => 'requisicao identica em andamento nao e reutilizada',
    'return response.clone();' => 'segunda chamada nao recebe clone independente da resposta',
    "return method + '\\n' + url + '\\n' + body;" => 'chave nao considera metodo, URL e body do carrinho',
    'request.then(clear, clear);' => 'estado de requisicao nao e liberado em sucesso e erro',
];

foreach ($dedupeChecks as $needle => $message) {
    if (!str_contains($dedupe, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

if (!str_contains($privacy, '/js/shipping-request-dedupe-v1.js')) {
    fwrite(STDERR, "FAIL: checkout nao carrega o wrapper de deduplicacao\n");
    exit(1);
}
if (!str_contains($privacy, "document.addEventListener('DOMContentLoaded', load")) {
    fwrite(STDERR, "FAIL: wrapper deve carregar depois dos scripts defer do checkout\n");
    exit(1);
}

echo "OK: checkout reutiliza requisicao de frete identica em andamento\n";
