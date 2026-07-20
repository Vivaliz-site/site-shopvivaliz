<?php
declare(strict_types=1);

$key = '036e6d865ffc4525b743d6dd53c3cb4a';
$host = 'shopvivaliz.com.br';
$keyLocation = 'https://shopvivaliz.com.br/' . $key . '.txt';
$sitemapUrl = 'https://shopvivaliz.com.br/sitemap.xml';
$endpoint = 'https://api.indexnow.org/indexnow';
$limit = max(1, min(10000, (int)($argv[1] ?? 200)));

function svin_http_get(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'header' => "User-Agent: ShopVivaliz-IndexNow/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('GET falhou: ' . $url);
    }
    return $body;
}

function svin_post_json(string $url, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Falha ao serializar payload');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 45,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json; charset=utf-8\r\n" .
                "User-Agent: ShopVivaliz-IndexNow/1.0\r\n",
            'content' => $json,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $statusLine = $headers[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $m);
    $status = isset($m[1]) ? (int)$m[1] : 0;

    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $headers];
}

try {
    $keyBody = trim(svin_http_get($keyLocation));
    if ($keyBody !== $key) {
        throw new RuntimeException('Arquivo de chave publico nao corresponde a chave configurada');
    }

    $sitemap = svin_http_get($sitemapUrl);
    if (preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $sitemap, $matches) !== false && !empty($matches[1])) {
        $locations = $matches[1];
    } else {
        throw new RuntimeException('Sitemap invalido ou sem URLs');
    }

    $urls = [];
    foreach ($locations as $entry) {
        $loc = trim(html_entity_decode((string)$entry, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($loc !== '' && str_starts_with($loc, 'https://' . $host . '/')) {
            $urls[] = $loc;
        }
        if (count($urls) >= $limit) {
            break;
        }
    }
    $urls = array_values(array_unique($urls));
    if ($urls === []) {
        throw new RuntimeException('Nenhuma URL valida encontrada no sitemap');
    }

    $response = svin_post_json($endpoint, [
        'host' => $host,
        'key' => $key,
        'keyLocation' => $keyLocation,
        'urlList' => $urls,
    ]);

    echo json_encode([
        'status' => in_array($response['status'], [200, 202], true) ? 'COMPROVADO' : 'FALHOU',
        'http_status' => $response['status'],
        'submitted_urls' => count($urls),
        'key_location' => $keyLocation,
        'endpoint' => $endpoint,
        'body' => trim($response['body']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    exit(in_array($response['status'], [200, 202], true) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'FALHOU: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
