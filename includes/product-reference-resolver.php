<?php
declare(strict_types=1);

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

/**
 * Resolve uma referencia operacional do catalogo para a linha local em
 * `products`, aceitando product_id local, olist_id, olist_product_id ou SKU.
 *
 * @return array<string,mixed>|null
 */
function svpr_resolve_product_row(PDO $db, int|string $productReference): ?array
{
    $reference = trim((string)$productReference);
    if ($reference === '') {
        return null;
    }

    $productColumns = svpr_table_columns($db, 'products');
    $olistColumns = svpr_table_columns($db, 'olist_products');

    $selectParts = ['p.*'];
    if (svpr_has_column($olistColumns, 'name')) {
        $selectParts[] = "COALESCE(NULLIF(p.name, ''), NULLIF(op.name, '')) AS name";
    } else {
        $selectParts[] = "COALESCE(NULLIF(p.name, ''), '') AS name";
    }
    $selectParts[] = "COALESCE(NULLIF(p.description, ''), '') AS description";
    if (svpr_has_column($olistColumns, 'primary_image_url')) {
        $selectParts[] = "COALESCE(NULLIF(p.image_url, ''), NULLIF(op.primary_image_url, '')) AS image_url";
    } else {
        $selectParts[] = "COALESCE(NULLIF(p.image_url, ''), '') AS image_url";
    }

    $olistIdSources = [];
    if (svpr_has_column($productColumns, 'olist_id')) {
        $olistIdSources[] = "NULLIF(CAST(p.olist_id AS CHAR), '')";
    }
    if (svpr_has_column($olistColumns, 'olist_id')) {
        $olistIdSources[] = "NULLIF(CAST(op.olist_id AS CHAR), '')";
    }
    if (svpr_has_column($productColumns, 'product_id')) {
        $olistIdSources[] = "NULLIF(CAST(p.product_id AS CHAR), '')";
    }
    if (svpr_has_column($olistColumns, 'olist_product_id')) {
        $olistIdSources[] = "NULLIF(CAST(op.olist_product_id AS CHAR), '')";
    }
    $selectParts[] = $olistIdSources !== []
        ? 'COALESCE(' . implode(', ', $olistIdSources) . ", '') AS olist_id"
        : "'' AS olist_id";

    $olistProductIdSources = [];
    if (svpr_has_column($productColumns, 'product_id')) {
        $olistProductIdSources[] = "NULLIF(CAST(p.product_id AS CHAR), '')";
    }
    if (svpr_has_column($productColumns, 'olist_product_id')) {
        $olistProductIdSources[] = "NULLIF(CAST(p.olist_product_id AS CHAR), '')";
    }
    if (svpr_has_column($olistColumns, 'olist_product_id')) {
        $olistProductIdSources[] = "NULLIF(CAST(op.olist_product_id AS CHAR), '')";
    }
    if (svpr_has_column($olistColumns, 'olist_id')) {
        $olistProductIdSources[] = "NULLIF(CAST(op.olist_id AS CHAR), '')";
    }
    if (svpr_has_column($productColumns, 'olist_id')) {
        $olistProductIdSources[] = "NULLIF(CAST(p.olist_id AS CHAR), '')";
    }
    $selectParts[] = $olistProductIdSources !== []
        ? 'COALESCE(' . implode(', ', $olistProductIdSources) . ", '') AS olist_product_id"
        : "'' AS olist_product_id";

    $joinParts = [];
    if (svpr_has_column($productColumns, 'sku') && svpr_has_column($olistColumns, 'sku')) {
        $joinParts[] = 'op.sku = p.sku';
    }
    if (svpr_has_column($productColumns, 'olist_id') && svpr_has_column($olistColumns, 'olist_id')) {
        $joinParts[] = "(TRIM(COALESCE(CAST(p.olist_id AS CHAR), '')) <> '' AND CAST(op.olist_id AS CHAR) = CAST(p.olist_id AS CHAR))";
    }
    if (svpr_has_column($productColumns, 'product_id') && svpr_has_column($olistColumns, 'olist_product_id')) {
        $joinParts[] = "(TRIM(COALESCE(CAST(p.product_id AS CHAR), '')) <> '' AND CAST(op.olist_product_id AS CHAR) = CAST(p.product_id AS CHAR))";
    }
    if (svpr_has_column($productColumns, 'olist_product_id') && svpr_has_column($olistColumns, 'olist_product_id')) {
        $joinParts[] = "(TRIM(COALESCE(CAST(p.olist_product_id AS CHAR), '')) <> '' AND CAST(op.olist_product_id AS CHAR) = CAST(p.olist_product_id AS CHAR))";
    }
    $joinSql = $joinParts !== []
        ? 'LEFT JOIN olist_products op ON ' . implode(' OR ', $joinParts)
        : '';

    $whereParts = [
        'p.sku = ?',
        'CAST(p.id AS CHAR) = ?',
    ];
    $params = [$reference, $reference];

    foreach (['product_id', 'olist_id', 'olist_product_id'] as $field) {
        if (svpr_has_column($productColumns, $field)) {
            $whereParts[] = "CAST(COALESCE(p.{$field}, '') AS CHAR) = ?";
            $params[] = $reference;
        }
    }

    if ($joinSql !== '') {
        foreach (['olist_product_id', 'olist_id'] as $field) {
            if (svpr_has_column($olistColumns, $field)) {
                $whereParts[] = "CAST(COALESCE(op.{$field}, '') AS CHAR) = ?";
                $params[] = $reference;
            }
        }
    }

    $orderField = svpr_has_column($productColumns, 'updated_at') ? 'p.updated_at DESC, ' : '';
    $sql = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM products p '
        . $joinSql
        . ' WHERE ' . implode(' OR ', $whereParts)
        . ' ORDER BY ' . $orderField . 'p.id DESC LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function svpr_resolve_local_product_id(PDO $db, int|string $productReference): ?int
{
    $row = svpr_resolve_product_row($db, $productReference);
    $productId = (int)($row['id'] ?? 0);

    return $productId > 0 ? $productId : null;
}
