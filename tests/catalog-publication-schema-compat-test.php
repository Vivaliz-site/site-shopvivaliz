<?php
declare(strict_types=1);

$schemaPath = __DIR__ . '/../includes/catalog-publication-schema.php';
$source = file_get_contents($schemaPath);
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "FAIL: catalog publication schema source unavailable\n");
    exit(1);
}

$codeWithoutComments = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token)) {
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codeWithoutComments .= $token[1];
        continue;
    }
    $codeWithoutComments .= $token;
}

if (preg_match('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS/i', $codeWithoutComments) === 1) {
    fwrite(STDERR, "FAIL: CREATE INDEX IF NOT EXISTS is not compatible with the production MySQL version\n");
    exit(1);
}

$required = [
    'information_schema.STATISTICS',
    'function svcp_index_exists',
    'function svcp_add_index_if_missing',
    'idx_catalog_optimizations_staging_channel_product_status',
    'idx_catalog_optimizations_staging_status_created',
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fwrite(STDERR, "FAIL: required idempotent index guard missing: {$snippet}\n");
        exit(1);
    }
}

require_once $schemaPath;

if (svcp_sql_identifier('catalog_optimizations_staging') !== '`catalog_optimizations_staging`') {
    fwrite(STDERR, "FAIL: valid SQL identifier was not quoted safely\n");
    exit(1);
}

$rejected = false;
try {
    svcp_sql_identifier('catalog_optimizations_staging; DROP TABLE products');
} catch (InvalidArgumentException) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "FAIL: unsafe SQL identifier was accepted\n");
    exit(1);
}

echo "catalog-publication-schema-compat: ok\n";
