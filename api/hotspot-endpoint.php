<?php
declare(strict_types=1);
/**
 * Rodada 7 (2026-08-19): endpoint neutralizado.
 *
 * Estava 100% morto: nenhum rewrite no .htaccess leva a /api/hotspot/track
 * ou /api/hotspot/conversion (as unicas duas rotas que este arquivo sabia
 * tratar -- ele so age se REQUEST_URI contiver um desses dois caminhos), e
 * public/hotspot-tracker.js (que fazia os POSTs) nunca era incluido em
 * nenhuma pagina do site. Mesmo se o roteamento fosse corrigido, o codigo
 * original tinha dois problemas: file_put_contents('.cart-abandonments.jsonl', ...)
 * com caminho relativo ao CWD (dentro do release imutavel) e um
 * read-modify-write de '.email-queue.json' sem LOCK_EX -- e nenhum
 * consumidor de '.email-queue.json' existe no repo, entao o e-mail de
 * recuperacao de carrinho nunca seria enviado de qualquer forma. Ou seja: a
 * recuperacao de carrinho abandonado nao existe hoje, apesar do que estes
 * arquivos sugerem. Ver R7-10 no relatorio da Rodada 7 -- se a feature for
 * priorizada de verdade, tratar como trabalho novo (rota real + LOCK_EX +
 * caminho absoluto em storage/ + consumidor de fila + rate limit), nao
 * reaproveitar este codigo.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'endpoint_removed',
    'message' => 'Recuperação de carrinho abandonado nunca esteve conectada (rota inexistente, JS nunca incluído). Ver docs/AGENTS.md, Rodada 7, R7-10.',
]);
