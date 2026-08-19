<?php
declare(strict_types=1);

function policy_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

// Rodada 6 (2026-08-19): ATENCAO -- este teste le o FONTE de index.php, mas
// o banner de anuncio (barra "🎁 X OFF COM O CUPOM ...") e montado em tempo
// de execucao a partir do banco (includes/navbar.php + sv_primary_active_coupon()),
// nao aparece literalmente no fonte de index.php. Confirmado ao vivo via
// curl que https://shopvivaliz.com.br/ (e /catalogo/) anunciam VIVALIZ10 na
// barra HOJE, MESMO com este teste passando -- porque o cupom VIVALIZ10 e
// semeado com display_in_navbar=1/display_in_popup=1 em
// includes/account-schema.php, e o teste abaixo nunca olha esse caminho.
// Ou seja: este teste da garantia falsa sobre o HTML que o cliente ve. Nao
// alterado nesta rodada porque a pergunta de fundo (o VIVALIZ10 pode ou nao
// ser anunciado publicamente?) e decisao comercial do Fred, nao tecnica --
// ver R6-4 no relatorio da Rodada 6. Antes de confiar neste teste de novo,
// ou (a) trocar a asserção por render real / curl contra o HTML servido, ou
// (b) confirmar que a politica mudou e reescrever as asserções.
$index = file_get_contents(dirname(__DIR__) . '/index.php') ?: '';
$sanitizer = file_get_contents(dirname(__DIR__) . '/includes/product-trust-sanitizer.php') ?: '';

policy_assert(!str_contains($index, 'VIVALIZ10'), 'public home must not advertise legacy VIVALIZ10');
policy_assert(!str_contains($index, 'PRIMEIRA10'), 'public home must not advertise legacy PRIMEIRA10');
policy_assert(!preg_match('/10%[^\n]{0,100}(primeira|1.?)\s*compra/iu', $index), 'public home must not claim 10% first purchase');
policy_assert(str_contains($index, '3% OFF automatico no carrinho'), 'home must advertise the approved automatic 3% cart offer');
policy_assert(str_contains($sanitizer, 'PIX disponível no checkout'), 'PIX claim must stay neutral until checkout-authoritative');
policy_assert(str_contains($sanitizer, 'Parcelamento disponível no checkout'), 'installment claim must stay neutral until checkout-authoritative');

fwrite(STDOUT, "commercial_policy_regression=ok\n");
