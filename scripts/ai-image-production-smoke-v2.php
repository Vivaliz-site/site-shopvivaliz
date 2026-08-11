<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}
if ((string)getenv('AI_PRODUCTION_SMOKE_CONFIRM') !== 'RUN_ALL_PROVIDER_CHANNEL_SMOKES') {
    fwrite(STDERR, "Refusing to run without AI_PRODUCTION_SMOKE_CONFIRM.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/admin/ai-image-studio/process_item.php';
$storefrontResolver = $root . '/includes/storefront-image-source.php';
if (is_file($storefrontResolver)) {
    require_once $storefrontResolver;
}

function image_smoke_arg(string $name): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return substr((string)$arg, strlen($prefix));
        }
    }
    return '';
}

function image_smoke_sanitize(string $message): string
{
    $message = preg_replace('/\b(?:sk-(?:proj-)?|AIza|ant-api)[A-Za-z0-9_\-]{8,}\b/u', '[secret-redacted]', $message) ?? $message;
    $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [secret-redacted]', $message) ?? $message;
    $message = preg_replace('/([?&](?:key|api_key|token|access_token)=)[^&\s]+/i', '$1[secret-redacted]', $message) ?? $message;
    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    return function_exists('mb_substr') ? mb_substr($message, 0, 420, 'UTF-8') : substr($message, 0, 420);
}

/** @return list<string> */
function image_smoke_sources(PDO $db, array $product, string $root): array
{
    $sources = [];
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') {
        try {
            $stmt = $db->prepare(
                "SELECT site_url, local_url, image_url, original_url_olist, original_url, position, is_primary, id "
                . "FROM olist_product_images "
                . "WHERE sku = ? AND (status IS NULL OR status = '' OR status NOT IN ('error','deleted','inactive')) "
                . "ORDER BY is_primary DESC, position ASC, id ASC LIMIT 16"
            );
            $stmt->execute([$sku]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $resolved = '';
                if (function_exists('svsis_resolve_image_url')) {
                    $resolved = trim((string)svsis_resolve_image_url($row, $root));
                } else {
                    foreach (['original_url_olist', 'image_url', 'original_url', 'site_url', 'local_url'] as $field) {
                        $value = trim((string)($row[$field] ?? ''));
                        if ($value === '') continue;
                        if (preg_match('~^https?://~i', $value) === 1) {
                            $resolved = $value;
                            break;
                        }
                        if (str_starts_with($value, '/') && is_file($root . $value)) {
                            $resolved = $value;
                            break;
                        }
                    }
                }
                if ($resolved !== '' && !in_array($resolved, $sources, true)) {
                    $sources[] = $resolved;
                }
            }
        } catch (Throwable) {
            // Continua com a imagem principal legada quando a galeria opcional não existir.
        }
    }
    $legacy = trim((string)($product['image_ref'] ?? ''));
    if ($legacy !== '' && !in_array($legacy, $sources, true)) {
        $sources[] = $legacy;
    }
    return $sources;
}

/** @return array{product_id:int,product:array<string,mixed>,base_image_path:string,base_image_is_temp:bool,source_kind:string} */
function image_smoke_pick_fixture(PDO $db, string $root): array
{
    $stmt = $db->query(
        "SELECT id FROM products "
        . "WHERE COALESCE(NULLIF(TRIM(name), ''), '') <> '' "
        . "ORDER BY CASE WHEN COALESCE(NULLIF(TRIM(description), ''), '') <> '' THEN 0 ELSE 1 END, id ASC LIMIT 120"
    );
    $ids = $stmt ? array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
    $lastError = '';
    foreach ($ids as $productId) {
        if ($productId <= 0) continue;
        $product = ai_studio_fetch_product($db, $productId);
        if ($product === null) continue;
        foreach (image_smoke_sources($db, $product, $root) as $source) {
            try {
                $path = ai_studio_resolve_base_image($source, $root, $productId);
                ai_studio_validate_image_file($path, 600);
                $product['image_ref'] = $source;
                return [
                    'product_id' => $productId,
                    'product' => $product,
                    'base_image_path' => $path,
                    'base_image_is_temp' => str_starts_with($path, AI_STUDIO_BASE_IMAGE_TMP_DIR),
                    'source_kind' => preg_match('~^https?://~i', $source) === 1 ? 'remote' : 'local',
                ];
            } catch (Throwable $e) {
                $lastError = image_smoke_sanitize($e->getMessage());
            }
        }
    }
    throw new RuntimeException('Nenhum produto com foto real valida foi encontrado. ' . $lastError);
}

function image_smoke_requested_contributed(string $requested, string $used): bool
{
    return match ($requested) {
        'claude' => str_starts_with($used, 'claude_optimized+'),
        'groq' => str_starts_with($used, 'groq_optimized+'),
        default => $used === $requested || str_ends_with($used, '+' . $requested),
    };
}

/** @return array<string,mixed> */
function image_smoke_run(PDO $db, array $fixture, string $channel, string $provider): array
{
    $started = microtime(true);
    $productId = (int)$fixture['product_id'];
    try {
        $result = ai_studio_process_item(
            $db,
            $productId,
            $provider,
            ['white'],
            null,
            $channel,
            $fixture['product'],
            (string)$fixture['base_image_path'],
            false,
            null
        );
        $rows = is_array($result['results'] ?? null) ? $result['results'] : [];
        $pending = null;
        $firstError = '';
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (($row['status'] ?? '') === 'pending') {
                $pending = $row;
                break;
            }
            if ($firstError === '' && is_string($row['error'] ?? null)) {
                $firstError = (string)$row['error'];
            }
        }
        $providerUsed = trim((string)($result['provider_used'] ?? $provider));
        if (!is_array($pending)) {
            $message = trim((string)($result['error'] ?? ''));
            if ($message === '') $message = $firstError !== '' ? $firstError : 'Nenhuma imagem chegou a pending.';
            return [
                'kind' => 'image-real-path',
                'channel' => $channel,
                'provider_requested' => $provider,
                'provider_used' => $providerUsed,
                'requested_provider_contributed' => image_smoke_requested_contributed($provider, $providerUsed),
                'status' => 'FAIL',
                'staging_id' => (int)($rows[0]['staging_id'] ?? 0),
                'error' => image_smoke_sanitize($message),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
        }
        $quality = is_array($pending['quality'] ?? null) ? $pending['quality'] : [];
        return [
            'kind' => 'image-real-path',
            'channel' => $channel,
            'provider_requested' => $provider,
            'provider_used' => $providerUsed,
            'requested_provider_contributed' => image_smoke_requested_contributed($provider, $providerUsed),
            'status' => 'PASS',
            'staging_id' => (int)($pending['staging_id'] ?? 0),
            'width' => (int)($quality['width'] ?? 0),
            'height' => (int)($quality['height'] ?? 0),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    } catch (Throwable $e) {
        return [
            'kind' => 'image-real-path',
            'channel' => $channel,
            'provider_requested' => $provider,
            'provider_used' => '',
            'requested_provider_contributed' => false,
            'status' => 'FAIL',
            'staging_id' => 0,
            'error' => image_smoke_sanitize($e->getMessage()),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}

$outputPath = image_smoke_arg('json-output');
$expectedSha = image_smoke_arg('expected-sha');
if ($expectedSha !== '') {
    $releaseShaPath = $root . '/.release-sha';
    $activeSha = is_file($releaseShaPath) ? trim((string)file_get_contents($releaseShaPath)) : '';
    if ($activeSha !== '' && !hash_equals($expectedSha, $activeSha)) {
        fwrite(STDERR, "Active release does not match expected SHA.\n");
        exit(3);
    }
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(4);
}
svcp_ensure_schema($db);
$fixture = image_smoke_pick_fixture($db, $root);
$channels = ['site', 'ml', 'shopee', 'amazon', 'tiktok'];
$providers = ['openai', 'google', 'claude', 'openrouter', 'groq'];
$results = [];

foreach ($channels as $channel) {
    foreach ($providers as $provider) {
        $row = image_smoke_run($db, $fixture, $channel, $provider);
        $results[] = $row;
        fwrite(STDOUT, sprintf(
            "image-real channel=%s provider=%s used=%s contributed=%s status=%s\n",
            $channel,
            $provider,
            (string)($row['provider_used'] ?? ''),
            !empty($row['requested_provider_contributed']) ? 'true' : 'false',
            (string)$row['status']
        ));
    }
}

if (!empty($fixture['base_image_is_temp']) && is_file((string)$fixture['base_image_path'])) {
    @unlink((string)$fixture['base_image_path']);
}

$pass = count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'PASS'));
$contributed = count(array_filter($results, static fn(array $row): bool => !empty($row['requested_provider_contributed'])));
$report = [
    'schema' => 'shopvivaliz.ai-image-smoke-matrix.v2',
    'generated_at' => gmdate(DATE_ATOM),
    'release_sha' => $expectedSha,
    'product_id' => (int)$fixture['product_id'],
    'image_source_kind' => (string)$fixture['source_kind'],
    'expected_total' => 25,
    'attempted_total' => count($results),
    'pass_total' => $pass,
    'fail_total' => count($results) - $pass,
    'requested_provider_contributed_total' => $contributed,
    'all_combinations_attempted' => count($results) === 25,
    'publication_performed' => false,
    'results' => $results,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Failed to encode report.\n");
    exit(5);
}
if ($outputPath !== '') {
    $dir = dirname($outputPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create output directory.\n");
        exit(5);
    }
    file_put_contents($outputPath, $json . PHP_EOL, LOCK_EX);
}
fwrite(STDOUT, sprintf(
    "image_matrix attempted=%d pass=%d fail=%d requested_contributed=%d publication=false product_id=%d\n",
    count($results),
    $pass,
    count($results) - $pass,
    $contributed,
    (int)$fixture['product_id']
));
exit(count($results) === 25 ? 0 : 6);
