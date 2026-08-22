<?php
declare(strict_types=1);

if (!function_exists('svcr_products') && is_file(__DIR__ . '/catalog-runtime.php')) {
    require_once __DIR__ . '/catalog-runtime.php';
}

/** @return array<string,bool> */
function svpr_table_columns(PDO $db, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
    $columns = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $field = strtolower(trim((string)($row['Field'] ?? '')));
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $cache[$table] = $columns;
}

/** @param array<string,bool> $columns */
function svpr_has_column(array $columns, string $field): bool
{
    return isset($columns[strtolower($field)]);
}

/** @param list<string> $candidates */
function svpr_push_candidate(array &$candidates, mixed $value): void
{
    $candidate = trim((string)$value);
    if ($candidate === '' || in_array($candidate, $candidates, true)) {
        return;
    }
    $candidates[] = $candidate;
}

/** @return array<string,list<string>> */
function svpr_runtime_reference_index(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }

    $index = [];
    if (!function_exists('svcr_products')) {
        return $index;
    }

    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $references = [];
        svpr_push_candidate($references, $row['id'] ?? '');
        svpr_push_candidate($references, $row['olist_product_id'] ?? '');
        svpr_push_candidate($references, $row['sku'] ?? '');

        if ($references === []) {
            continue;
        }

        foreach ($references as $reference) {
            $key = strtolower($reference);
            $known = $index[$key] ?? [];
            foreach ($references as $candidate) {
                svpr_push_candidate($known, $candidate);
            }
            $index[$key] = $known;
        }
    }

    return $index;
}

/** @return list<string> */
function svpr_reference_candidates(int|string $productReference): array
{
    $candidates = [];
    svpr_push_candidate($candidates, $productReference);

    $reference = trim((string)$productReference);
    if ($reference === '') {
        return $candidates;
    }

    $runtimeMatches = svpr_runtime_reference_index()[strtolower($reference)] ?? [];
    foreach ($runtimeMatches as $candidate) {
        svpr_push_candidate($candidates, $candidate);
    }

    return $candidates;
}

/**
 * Resolve uma referencia operacional usando somente o runtime derivado do
 * ERP Olist/Tiny v3. A tabela local `products` pode existir para chaves
 * internas de UX, mas nao e fonte de verdade para nome, descricao, imagem,
 * preco, estoque, status ou cadastro comercial.
 *
 * @return array<string,mixed>|null
 */
function svpr_resolve_product_row_once(PDO $db, string $reference): ?array
{
    unset($db);
    $needle = strtolower(trim($reference));
    if ($needle === '' || !function_exists('svcr_products')) {
        return null;
    }

    foreach (svcr_products() as $row) {
        if (!is_array($row)) continue;
        foreach ([$row['id'] ?? '', $row['olist_product_id'] ?? '', $row['sku'] ?? ''] as $candidate) {
            if (strtolower(trim((string)$candidate)) !== $needle) continue;
            $row['erp_authoritative'] = true;
            $row['source_of_truth'] = 'erp_olist_tiny_v3';
            return $row;
        }
    }

    return null;
}

/**
 * Resolve uma referencia operacional do catalogo a partir dos aliases
 * expandidos do proprio runtime ERP.
 *
 * @return array<string,mixed>|null
 */
function svpr_resolve_product_row(PDO $db, int|string $productReference): ?array
{
    foreach (svpr_reference_candidates($productReference) as $candidate) {
        $row = svpr_resolve_product_row_once($db, $candidate);
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

function svpr_resolve_local_product_id(PDO $db, int|string $productReference): ?int
{
    $row = svpr_resolve_product_row($db, $productReference);
    $productId = (int)($row['id'] ?? 0);

    return $productId > 0 ? $productId : null;
}
