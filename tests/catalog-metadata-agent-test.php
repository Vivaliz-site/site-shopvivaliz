<?php

declare(strict_types=1);

require_once __DIR__ . '/../agents/v9.2.84/app/CatalogMetadataAgent.php';

function sv_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/sv-catalog-agent-' . bin2hex(random_bytes(6));
$storage = $root . '/storage';
mkdir($storage, 0775, true);

$payload = [
    'itens' => [
        [
            'sku' => 'TEST-ASSENTO',
            'descricao' => 'Assento Sanitário Oval Universal Soft Branco Astra',
            'categoria' => ['nome' => 'Vasos Decorativos', 'caminhoCompleto' => 'Vasos Decorativos'],
            'precos' => ['preco' => 40.04, 'precoPromocional' => 0],
            'estoque' => ['quantidade' => 7],
        ],
        [
            'sku' => 'TEST-COMEDOURO',
            'descricao' => 'Comedouro Automatico Regulavel Caes Gatosv5 Kg Fercar',
            'categoria' => ['nome' => 'Comedouros'],
            'price' => 184.17,
            'stock' => 4,
        ],
    ],
];

$cache = $storage . '/products-cache-ativos.json';
file_put_contents($cache, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$before = json_decode((string)file_get_contents($cache), true);

$result = (new ShopvivalizCatalogMetadataAgent($root))->run(['catalog_metadata_limit' => 10]);
$after = json_decode((string)file_get_contents($cache), true);

sv_test_assert(($result['ok'] ?? false) === true, 'agent must complete successfully');
sv_test_assert(($result['execution_completed'] ?? false) === true, 'execution must be completed');
sv_test_assert((int)($result['changed_items'] ?? 0) === 2, 'two products must be repaired');
sv_test_assert(($after['itens'][0]['categoria']['nome'] ?? '') === 'Banheiro', 'sanitary seat category must be Banheiro');
sv_test_assert(str_contains((string)($after['itens'][1]['descricao'] ?? ''), 'Gatos 5'), 'title typo must be normalized');
sv_test_assert(($before['itens'][0]['precos'] ?? null) === ($after['itens'][0]['precos'] ?? null), 'nested pricing data must be unchanged');
sv_test_assert(($before['itens'][0]['estoque'] ?? null) === ($after['itens'][0]['estoque'] ?? null), 'nested inventory data must be unchanged');
sv_test_assert(($before['itens'][1]['price'] ?? null) === ($after['itens'][1]['price'] ?? null), 'flat pricing data must be unchanged');
sv_test_assert(($before['itens'][1]['stock'] ?? null) === ($after['itens'][1]['stock'] ?? null), 'flat inventory data must be unchanged');

$backupFiles = glob($root . '/storage/agent-evidence/catalog-cache-backups/catalog-*.json') ?: [];
sv_test_assert(count($backupFiles) === 1, 'one rollback backup must exist');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($root);

echo "catalog_metadata_agent_test=passed\n";
