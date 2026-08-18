<?php
/**
 * Atualiza o estoque do cache ativo pela fonte autoritativa da API v3:
 * GET /estoque/{idProduto}, campo `disponivel`.
 */
declare(strict_types=1);

function fev3_env(string ...$keys): string
{
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $root = dirname(__DIR__);
        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if ($k !== '' && getenv($k) === false) {
                    putenv("{$k}={$v}");
                    $_ENV[$k] = $v;
                }
            }
        }
        $tokensFile = $root . '/storage/private/tokens.json';
        if (is_file($tokensFile)) {
            $tokens = json_decode((string)file_get_contents($tokensFile), true);
            if (is_array($tokens)) {
                foreach ($tokens as $k => $v) {
                    if (is_string($v) && $v !== '' && getenv((string)$k) === false) {
                        putenv((string)$k . '=' . $v);
                        $_ENV[(string)$k] = $v;
                    }
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

/** @return array{limit:int,remaining:int,reset:int} */
function fev3_rate_defaults(): array
{
    return ['limit' => 0, 'remaining' => -1, 'reset' => 0];
}

/** @param array{limit:int,remaining:int,reset:int} $rate */
function fev3_wait_for_rate_limit(array $rate, bool $force = false): void
{
    $remaining = (int)($rate['remaining'] ?? -1);
    $reset = max(0, (int)($rate['reset'] ?? 0));
    if ($force || ($remaining >= 0 && $remaining <= 4)) {
        sleep(max(2, min(65, $reset + 1)));
    }
}

/**
 * Busca estoque disponivel de um produto. O endpoint retorna `saldo`,
 * `reservado` e `disponivel`; usamos `disponivel` para venda.
 */
function get_product_estoque_v3(string $idProduto, string $token): ?int
{
    $url = 'https://api.tiny.com.br/public-api/v3/estoque/' . rawurlencode($idProduto);
    $lastError = '';

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $rate = fev3_rate_defaults();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$rate): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) return $length;
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($name === 'x-ratelimit-limit') $rate['limit'] = (int)$value;
            if ($name === 'x-ratelimit-remaining') $rate['remaining'] = (int)$value;
            if ($name === 'x-ratelimit-reset') $rate['reset'] = (int)$value;
            return $length;
        });

        $response = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpStatus === 429) {
            $lastError = 'HTTP 429';
            fev3_wait_for_rate_limit($rate, true);
            continue;
        }

        if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
            $lastError = 'HTTP ' . $httpStatus . ' curl=' . ($curlError !== '' ? $curlError : 'none');
            if ($httpStatus >= 500 && $attempt < 4) {
                sleep(min(8, 1 << $attempt));
                continue;
            }
            break;
        }

        $data = json_decode((string)$response, true);
        if (is_array($data) && array_key_exists('disponivel', $data) && is_numeric($data['disponivel'])) {
            fev3_wait_for_rate_limit($rate, false);
            return max(0, (int)floor((float)$data['disponivel']));
        }

        $lastError = 'resposta sem campo disponivel';
        break;
    }

    error_log('[fetch-estoque-v3] Falha id=' . $idProduto . ' ' . $lastError);
    return null;
}

/** @return array{success:bool,total:int,updated:int,unresolved:int,available:int,error?:string} */
function enrich_cache_with_estoque_v3(): array
{
    $root = dirname(__DIR__);
    $cacheFile = $root . '/storage/products-cache-ativos.json';
    $lockFile = $root . '/storage/.products-stock-sync.lock';
    $token = fev3_access_token();

    if ($token === '') {
        return ['success' => false, 'total' => 0, 'updated' => 0, 'unresolved' => 0, 'available' => 0, 'error' => 'access_token_missing'];
    }
    if (!is_file($cacheFile)) {
        return ['success' => false, 'total' => 0, 'updated' => 0, 'unresolved' => 0, 'available' => 0, 'error' => 'active_cache_missing'];
    }

    $lock = fopen($lockFile, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return ['success' => false, 'total' => 0, 'updated' => 0, 'unresolved' => 0, 'available' => 0, 'error' => 'stock_lock_failed'];
    }

    try {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        $items = is_array($cache['itens'] ?? null) ? $cache['itens'] : [];
        if ($items === []) {
            return ['success' => false, 'total' => 0, 'updated' => 0, 'unresolved' => 0, 'available' => 0, 'error' => 'active_cache_empty'];
        }

        $updated = 0;
        $unresolved = 0;
        $available = 0;
        $requestCount = 0;

        foreach ($items as &$item) {
            $id = trim((string)($item['id'] ?? ''));
            if ($id === '') {
                $unresolved++;
                continue;
            }

            if ($requestCount > 0) usleep(650000);
            $requestCount++;
            $estoque = get_product_estoque_v3($id, $token);
            if ($estoque === null) {
                $unresolved++;
                continue;
            }

            // Sempre sobrescreve: um 0 antigo pode ter sido fabricado pela
            // listagem em lote e nao e fonte autoritativa.
            $item['estoque_disponivel'] = $estoque;
            $item['estoque_sync_at'] = gmdate(DATE_ATOM);
            $updated++;
            if ($estoque > 0) $available++;
        }
        unset($item);

        $total = count($items);
        if ($updated === 0) {
            return ['success' => false, 'total' => $total, 'updated' => 0, 'unresolved' => $unresolved, 'available' => 0, 'error' => 'no_authoritative_stock_resolved'];
        }

        // Evita publicar um refresh amplamente incompleto. Mantemos o cache
        // anterior intacto e tentamos novamente no proximo ciclo.
        $minimum = max(1, (int)floor($total * 0.90));
        if ($updated < $minimum) {
            return ['success' => false, 'total' => $total, 'updated' => $updated, 'unresolved' => $unresolved, 'available' => $available, 'error' => 'authoritative_stock_coverage_below_90pct'];
        }

        $cache['itens'] = $items;
        $cache['estoque_timestamp'] = gmdate(DATE_ATOM);
        $cache['estoque_updated'] = $updated;
        $cache['estoque_unresolved'] = $unresolved;
        $cache['estoque_available'] = $available;

        $tmp = $cacheFile . '.tmp.' . getmypid();
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json) || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $cacheFile)) {
            @unlink($tmp);
            return ['success' => false, 'total' => $total, 'updated' => $updated, 'unresolved' => $unresolved, 'available' => $available, 'error' => 'atomic_cache_write_failed'];
        }

        return ['success' => true, 'total' => $total, 'updated' => $updated, 'unresolved' => $unresolved, 'available' => $available];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

$result = enrich_cache_with_estoque_v3();
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (!$result['success']) exit(1);
