<?php
declare(strict_types=1);
/**
 * Rodada 7 (2026-08-19): endpoint removido (nao so bloqueado).
 *
 * Este arquivo nunca chamava a API da Shopee de verdade -- os numeros de
 * "sucesso" (198/198 produtos, "imagens_geradas: 198", status "concluido")
 * eram literais escritos no codigo, e o script gravava esse log de sucesso
 * fabricado em disco a cada execucao. Qualquer relatorio interno que tenha
 * lido esses logs herdou numeros falsos. Diferente de outros achados desta
 * serie de rodadas, este nao foi so neutralizado com 410 -- o conteudo foi
 * substituido por completo, porque enquanto o arquivo original existisse,
 * alguem poderia reagendá-lo (mesmo sem rota publica) e voltar a poluir os
 * logs com sucesso falso. Ver R7-6 no relatorio da Rodada 7.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'error' => 'endpoint_removed',
    'message' => 'Script removido: fabricava resultado de sucesso (198/198) sem chamar a API da Shopee. Ver docs/AGENTS.md, Rodada 7, R7-6.',
]);
