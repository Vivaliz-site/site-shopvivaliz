<?php
declare(strict_types=1);
/**
 * Rodada 7 (2026-08-19): endpoint neutralizado.
 *
 * Executava ALTER TABLE + UPDATE em massa em olist_products/
 * olist_product_images via GET publico, sem nenhuma autenticacao, quando
 * ?apply=1 era passado. Hoje falhava fechado por acidente (svz_pdo() nao
 * achava nenhum bootstrap de banco dentro de claude/), mas bastava alguem
 * criar claude/config.php pra virar uma ferramenta de destruicao de dados
 * aberta ao publico. Toda a arvore claude/ tambem foi bloqueada no
 * .htaccess nesta mesma rodada (R7-1), mas este arquivo especifico foi
 * neutralizado tambem como defesa em profundidade, caso a arvore seja
 * reaberta parcialmente no futuro. Era um script de reparo pontual de
 * 2026-07, nao uma rotina permanente. Ver R7-2 no relatorio da Rodada 7.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'endpoint_removed',
    'message' => 'Script de reparo pontual desativado. Se ainda for necessario, mover para scripts/ com sv_require_agent_key() e POST.',
]);
