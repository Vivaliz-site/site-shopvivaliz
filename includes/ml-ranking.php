<?php
declare(strict_types=1);

function sv_ml_scores(): array
{
    static $scores = null;
    if (is_array($scores)) return $scores;
    $path = dirname(__DIR__) . '/storage/ml/product-scores.json';
    if (!is_file($path)) return $scores = [];
    $data = json_decode((string)file_get_contents($path), true);
    return $scores = is_array($data['scores'] ?? null) ? $data['scores'] : [];
}

function sv_ml_score(array $product): ?float
{
    $scores = sv_ml_scores();
    foreach (['olist_product_id', 'id', 'sku', 'slug'] as $key) {
        $id = trim((string)($product[$key] ?? ''));
        if ($id !== '' && array_key_exists($id, $scores)) return (float)$scores[$id];
    }
    return null;
}

function sv_ml_rank_products(array $products): array
{
    $indexed = [];
    foreach (array_values($products) as $position => $product) {
        if (!is_array($product)) continue;
        $indexed[] = ['product' => $product, 'score' => sv_ml_score($product), 'position' => $position];
    }
    usort($indexed, static function (array $a, array $b): int {
        if ($a['score'] === null && $b['score'] === null) return $a['position'] <=> $b['position'];
        if ($a['score'] === null) return 1;
        if ($b['score'] === null) return -1;
        return ($b['score'] <=> $a['score']) ?: ($a['position'] <=> $b['position']);
    });
    return array_column($indexed, 'product');
}
