<?php
declare(strict_types=1);

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

    $stmt = $db->prepare(
        "SELECT
            p.*,
            COALESCE(NULLIF(p.name, ''), NULLIF(op.name, '')) AS name,
            COALESCE(NULLIF(p.description, ''), '') AS description,
            COALESCE(NULLIF(p.image_url, ''), NULLIF(op.primary_image_url, '')) AS image_url,
            COALESCE(NULLIF(CAST(p.olist_id AS CHAR), ''), NULLIF(CAST(op.olist_id AS CHAR), ''), NULLIF(CAST(op.olist_product_id AS CHAR), '')) AS olist_id,
            COALESCE(NULLIF(CAST(op.olist_product_id AS CHAR), ''), NULLIF(CAST(op.olist_id AS CHAR), ''), NULLIF(CAST(p.olist_id AS CHAR), '')) AS olist_product_id
         FROM products p
         LEFT JOIN olist_products op
           ON op.sku = p.sku
           OR (
                TRIM(COALESCE(CAST(p.olist_id AS CHAR), '')) <> ''
                AND CAST(op.olist_id AS CHAR) = CAST(p.olist_id AS CHAR)
           )
         WHERE p.sku = ?
            OR CAST(p.id AS CHAR) = ?
            OR CAST(COALESCE(p.olist_id, '') AS CHAR) = ?
            OR CAST(COALESCE(op.olist_product_id, '') AS CHAR) = ?
            OR CAST(COALESCE(op.olist_id, '') AS CHAR) = ?
         ORDER BY p.updated_at DESC, p.id DESC
         LIMIT 1"
    );
    $stmt->execute([$reference, $reference, $reference, $reference, $reference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function svpr_resolve_local_product_id(PDO $db, int|string $productReference): ?int
{
    $row = svpr_resolve_product_row($db, $productReference);
    $productId = (int)($row['id'] ?? 0);

    return $productId > 0 ? $productId : null;
}
