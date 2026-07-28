<?php
declare(strict_types=1);

/**
 * Compara precos da API oficial Tiny/Olist (fonte de verdade) contra o que
 * esta em storage/products-cache.json e (se disponivel) na tabela
 * olist_products do banco local. Apenas leitura -- nao altera preco,
 * catalogo, banco nem cache.
 *
 * Uso:
 *   php scripts/compare-tiny-prices.php [--changed-today] [--source=api] [--fail-on-diff]
 *
 * Credenciais: lidas exclusivamente de variaveis de ambiente (.env local via
 * svtop_env()/getenv()), nunca hardcoded, nunca de arquivo .txt. O token de
 * acesso e renovado automaticamente via svtop_tiny_get_token() (refresh_token
 * OAuth), a mesma funcao usada em producao por includes/tiny-order-push.php.
 */

require_once __DIR__ . '/../includes/tiny-order-push.php';

$changedToday = in_array('--changed-today', $argv, true);
$failOnDiff   = in_array('--fail-on-diff', $argv, true);

function svctp_println(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function svctp_err(string $line): void
{
    fwrite(STDERR, $line . "\n");
}

// --- 1. Token (renovacao automatica via refresh_token, sem hardcode) ---
if (!svtop_tiny_credentials_configured()) {
    svctp_err('ERRO_API: credenciais Tiny nao configuradas em ENV (.env). Abortando -- falha fechada, nenhum resultado deve ser tratado como OK.');
    exit(2);
}

$token = svtop_tiny_get_token();
if ($token === '') {
    svctp_err('ERRO_API: nao foi possivel obter/renovar o access_token via refresh_token. Abortando -- falha fechada.');
    exit(2);
}

// --- 2. Buscar produtos na API oficial (fonte de verdade), paginado ---
$apiProducts = [];
$offset = 0;
$limit = 100;
$apiError = null;

do {
    $res = svtop_tiny_get('/produtos?situacao=A&limit=' . $limit . '&offset=' . $offset, $token);
    if ($res['status'] !== 200) {
        $apiError = 'HTTP ' . $res['status'] . ' ao listar /produtos (offset=' . $offset . ')';
        break;
    }
    $body = json_decode((string)$res['body'], true);
    $items = is_array($body['itens'] ?? null) ? $body['itens'] : [];
    foreach ($items as $item) {
        $apiProducts[] = $item;
    }
    $total = (int)($body['paginacao']['total'] ?? count($apiProducts));
    $offset += $limit;
    // Respeita rate limit: pequena pausa entre paginas.
    usleep(300000); // 300ms
} while ($offset < $total && $apiError === null);

if ($apiError !== null) {
    svctp_err('ERRO_API: ' . $apiError . '. Abortando -- falha fechada, nenhum SKU deve ser reportado como OK.');
    exit(2);
}

if ($changedToday) {
    $today = date('Y-m-d');
    $apiProducts = array_values(array_filter($apiProducts, function ($item) use ($today) {
        $alt = (string)($item['dataAlteracao'] ?? '');
        return $alt !== '' && str_starts_with($alt, $today);
    }));
}

svctp_println('[*] Produtos da API (situacao=A' . ($changedToday ? ', alterados hoje' : '') . '): ' . count($apiProducts));

// --- 3. Carregar cache local (storage/products-cache.json) ---
$cachePath = __DIR__ . '/../storage/products-cache.json';
$cacheBySku = [];
$cacheAvailable = is_file($cachePath) && is_readable($cachePath);
if ($cacheAvailable) {
    $cacheRaw = json_decode((string)file_get_contents($cachePath), true);
    $cacheItems = [];
    if (is_array($cacheRaw)) {
        foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
            if (isset($cacheRaw[$key]) && is_array($cacheRaw[$key])) {
                $cacheItems = $cacheRaw[$key];
                break;
            }
        }
        if ($cacheItems === [] && array_is_list($cacheRaw)) {
            $cacheItems = $cacheRaw;
        }
    }
    foreach ($cacheItems as $row) {
        if (is_array($row) && isset($row['sku'])) {
            $cacheBySku[(string)$row['sku']] = $row;
        }
    }
} else {
    svctp_err('[!] AVISO: cache local storage/products-cache.json indisponivel -- SKUs serao marcados SEM_CACHE.');
}

// --- 4. Carregar tabela olist_products do banco, se acessivel ---
$dbBySku = [];
$dbAvailable = false;
$dbError = null;
try {
    $envFile = __DIR__ . '/../.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim(trim($v), "\"'");
            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
            }
        }
    }
    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    $port = (int)(getenv('DB_PORT') ?: 3306);
    if ($name !== '' && $user !== '' && class_exists('mysqli')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($host, $user, $pass, $name, $port);
        if (!$db->connect_errno) {
            $dbAvailable = true;
            $result = $db->query('SELECT sku, price FROM olist_products');
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $dbBySku[(string)$row['sku']] = (float)$row['price'];
                }
            }
            $db->close();
        } else {
            $dbError = 'connect_errno=' . $db->connect_errno;
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if (!$dbAvailable) {
    svctp_err('[!] AVISO: banco local (tabela olist_products) indisponivel' . ($dbError ? " ({$dbError})" : '') . ' -- SKUs serao marcados SEM_BANCO.');
}

// --- 5. Montar relatorio ---
$rows = [];
$hasDivergence = false;

foreach ($apiProducts as $item) {
    $sku = trim((string)($item['sku'] ?? ''));
    if ($sku === '') {
        continue;
    }
    $name = (string)($item['descricao'] ?? '');
    $precoApi = (float)($item['precos']['preco'] ?? 0);
    $precoPromoApi = (float)($item['precos']['precoPromocional'] ?? 0);
    $dataAlteracao = (string)($item['dataAlteracao'] ?? '');

    $cacheRow = $cacheBySku[$sku] ?? null;
    $precoCache = null;
    if ($cacheRow !== null) {
        $precoCache = (float)($cacheRow['precos']['preco'] ?? $cacheRow['price'] ?? $cacheRow['preco'] ?? 0);
    }

    $precoBanco = $dbBySku[$sku] ?? null;

    if ($cacheRow === null && !$cacheAvailable) {
        $status = 'SEM_CACHE';
    } elseif ($cacheRow === null) {
        $status = 'SEM_CACHE';
    } elseif (!$dbAvailable && $precoBanco === null) {
        // Cache disponivel: compara cache x API. Banco sinalizado a parte.
        $status = abs($precoApi - (float)$precoCache) > 0.009 ? 'DIVERGENTE' : 'OK';
    } else {
        $diffCache = abs($precoApi - (float)$precoCache) > 0.009;
        $diffBanco = $precoBanco !== null && abs($precoApi - $precoBanco) > 0.009;
        $status = ($diffCache || $diffBanco) ? 'DIVERGENTE' : 'OK';
    }

    if ($status === 'DIVERGENTE') {
        $hasDivergence = true;
    }

    $rows[] = [
        'sku' => $sku,
        'produto' => $name,
        'preco_api' => $precoApi,
        'preco_promo_api' => $precoPromoApi,
        'preco_cache' => $precoCache,
        'preco_banco' => $precoBanco,
        'status' => $status,
        'data_alteracao' => $dataAlteracao,
    ];
}

// --- 6. Imprimir relatorio (texto simples, sem PR automatico) ---
svctp_println('');
svctp_println(str_pad('SKU', 16) . str_pad('Produto', 45) . str_pad('Preco API', 12) . str_pad('Promo API', 12) . str_pad('Preco Cache', 13) . str_pad('Preco Banco', 13) . str_pad('Status', 12) . 'Data Alteracao');
foreach ($rows as $r) {
    svctp_println(
        str_pad($r['sku'], 16) .
        str_pad(mb_strimwidth($r['produto'], 0, 43, '..'), 45) .
        str_pad(number_format($r['preco_api'], 2, ',', '.'), 12) .
        str_pad(number_format($r['preco_promo_api'], 2, ',', '.'), 12) .
        str_pad($r['preco_cache'] === null ? '-' : number_format($r['preco_cache'], 2, ',', '.'), 13) .
        str_pad($r['preco_banco'] === null ? '-' : number_format($r['preco_banco'], 2, ',', '.'), 13) .
        str_pad($r['status'], 12) .
        $r['data_alteracao']
    );
}

$total = count($rows);
$divergentes = count(array_filter($rows, fn($r) => $r['status'] === 'DIVERGENTE'));
$semCache = count(array_filter($rows, fn($r) => $r['status'] === 'SEM_CACHE'));
svctp_println('');
svctp_println("[*] Total conferido: {$total} | DIVERGENTE: {$divergentes} | SEM_CACHE: {$semCache} | banco disponivel: " . ($dbAvailable ? 'sim' : 'nao'));

if ($failOnDiff && $hasDivergence) {
    exit(1);
}
exit(0);

