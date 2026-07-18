<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Use via CLI: php sync-tiny-categories.php\n");
    exit(1);
}

require_once __DIR__ . '/../includes/tiny-order-push.php';

$token = svtop_tiny_get_token();
if ($token === '') {
    fwrite(STDERR, "Tiny token indisponivel.\n");
    exit(1);
}

$response = svtop_tiny_list_categories($token);
if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
    fwrite(STDERR, "Falha ao buscar categorias: HTTP " . ($response['status'] ?? 0) . "\n");
    exit(1);
}

$tree = is_array($response['json'] ?? null) ? $response['json'] : [];
$flat = svtiny_flatten_categories($tree);

$baseDir = dirname(__DIR__) . '/storage/tiny';
if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    fwrite(STDERR, "Nao foi possivel criar diretorio de cache: {$baseDir}\n");
    exit(1);
}

svtiny_write_json_atomic($baseDir . '/categories-tree.json', [
    'fetched_at' => date(DATE_ATOM),
    'items' => $tree,
], true);

svtiny_write_json_atomic($baseDir . '/categories-flat.json', [
    'fetched_at' => date(DATE_ATOM),
    'items' => $flat,
], true);

echo json_encode([
    'ok' => true,
    'tree_count' => count($tree),
    'flat_count' => count($flat),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/**
 * @param array<int,array<string,mixed>> $nodes
 * @return array<int,array<string,mixed>>
 */
function svtiny_flatten_categories(array $nodes, string $prefix = ''): array
{
    $items = [];
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $name = trim((string)($node['descricao'] ?? ''));
        if ($name === '') {
            continue;
        }
        $path = $prefix === '' ? $name : $prefix . ' / ' . $name;
        $items[] = [
            'id' => (int)($node['id'] ?? 0),
            'descricao' => $name,
            'caminho' => $path,
            'filhas' => count((array)($node['filhas'] ?? [])),
        ];
        $children = is_array($node['filhas'] ?? null) ? $node['filhas'] : [];
        if ($children !== []) {
            $items = array_merge($items, svtiny_flatten_categories($children, $path));
        }
    }
    return $items;
}

function svtiny_write_json_atomic(string $path, array $payload, bool $pretty = false): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar o diretorio ' . $dir);
    }
    $tmp = tempnam($dir, 'tmp_');
    if ($tmp === false) {
        throw new RuntimeException('Nao foi possivel criar arquivo temporario');
    }
    $json = json_encode(
        $payload,
        ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    file_put_contents($tmp, $json);
    rename($tmp, $path);
}
