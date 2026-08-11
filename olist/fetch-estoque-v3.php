<?php
/**
 * Busca estoque real via API v3 OAuth da Tiny (GET /estoque/{id}), que calcula
 * `disponivel` corretamente inclusive para kits. Substitui fetch-estoque-v2.php
 * (API v2 legada com token estático `OLIST_INTEGRADOR_TOKEN`), removida em
 * 2026-07-18 -- ver docs/MEMORIA-AGENTES.md.
 */

declare(strict_types=1);

function fev3_env(string ...$keys): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
        // Só preenche o que faltou no .env -- storage/private/tokens.json e um
        // snapshot que pode ficar desatualizado (visto em 2026-08-11: token
        // la dentro expirado ha 5h, causando 401 por sobrescrever o token
        // valido do .env, que o renovador auto-atualiza a cada 3h).
        $tokensFile = dirname(__DIR__) . '/storage/private/tokens.json';
        if (is_file($tokensFile)) {
            $tokens = json_decode((string)file_get_contents($tokensFile), true);
            if (is_array($tokens)) {
                foreach ($tokens as $k => $v) {
                    if (is_string($v) && $v !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
                }
            }
        }
    }
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function fev3_access_token(): string
{
    return fev3_env('OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN');
}

/**
 * Busca estoque disponível de UM produto via API v3 (calculado pela Tiny,
 * correto inclusive para kits -- ver docs/TINY-ERP-API-V3.md).
 */
function get_product_estoque_v3(string $id_produto, string $token): ?int
{
    $url = "https://api.tiny.com.br/public-api/v3/estoque/{$id_produto}";

    // file_get_contents()/stream_context_create() e bloqueado com 401 pela
    // Cloudflare do Tiny mesmo com token valido (confirmado em 2026-08-11 --
    // ver olist/sync-on-webhook.php e docs/AGENTS.md). cURL com os mesmos
    // headers funciona normalmente.
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Accept: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpStatus === 429) {
        // Limite e 60 req/min por CONTA (nao por app) -- outros daemons
        // (olist-sync, daemon-sync-products) podem estar consumindo a
        // mesma cota ao mesmo tempo. Um retry apos respirar resolve a
        // maioria dos casos sem abortar o item inteiro.
        sleep(2);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
    }

    if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
        error_log("[fetch-estoque-v3] Falha id={$id_produto} status={$httpStatus} curl_error=" . ($curlError ?: 'none'));
        return null;
    }

    $data = json_decode($response, true);
    if (isset($data['disponivel'])) {
        return (int)$data['disponivel'];
    }

    error_log("[fetch-estoque-v3] Resposta sem campo disponivel id={$id_produto}: " . substr((string)$response, 0, 200));

    return null;
}

function enrich_cache_with_estoque_v3(): void
{
    $cacheFile = dirname(__DIR__) . '/storage/products-cache-ativos.json';
    $token = fev3_access_token();

    if ($token === '') {
        error_log('[fetch-estoque-v3] Access token OAuth nao configurado');
        return;
    }

    if (!is_file($cacheFile)) {
        error_log('[fetch-estoque-v3] Cache nao encontrado');
        return;
    }

    $cache = json_decode((string)file_get_contents($cacheFile), true);
    if (!isset($cache['itens'])) {
        return;
    }

    $updatedCount = 0;

    foreach ($cache['itens'] as &$item) {
        if (isset($item['id']) && !isset($item['estoque_disponivel'])) {
            $estoque = get_product_estoque_v3((string)$item['id'], $token);

            if ($estoque !== null) {
                $item['estoque_disponivel'] = $estoque;
                $updatedCount++;
            }

            usleep(200000);
        }
    }
    unset($item);

    file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    error_log("[fetch-estoque-v3] Enriquecidos {$updatedCount} produtos com estoque via v3");
}

enrich_cache_with_estoque_v3();
echo json_encode([
    'sucesso'   => true,
    'mensagem'  => 'Cache enriquecido com dados de estoque via API v3',
]);
