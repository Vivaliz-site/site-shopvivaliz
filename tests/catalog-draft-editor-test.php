<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/catalog-optimization/src/CatalogDraftEditor.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nEsperado: " . var_export($expected, true) . "\nAtual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "PDO SQLite não está disponível para o teste de staging.\n");
    exit(1);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE catalog_optimizations_staging ('
    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, channel TEXT NOT NULL, '
    . 'optimized_title TEXT NOT NULL, optimized_description TEXT NOT NULL, bullet_points_json TEXT NOT NULL, '
    . 'seo_keywords TEXT NOT NULL, marketing_hooks TEXT NOT NULL, meta_data_json TEXT NOT NULL, '
    . 'status TEXT NOT NULL, error_message TEXT NULL, publication_summary_json TEXT NULL, '
    . 'published_at TEXT NULL, updated_at TEXT NULL)'
);
$db->exec(
    "INSERT INTO catalog_optimizations_staging "
    . "(product_id, channel, optimized_title, optimized_description, bullet_points_json, seo_keywords, marketing_hooks, meta_data_json, status, error_message, publication_summary_json, published_at, updated_at) VALUES "
    . "(101, 'site', 'Título de teste', 'Descrição inicial', '[]', '', '', '{}', 'publication_failed', 'erro anterior', '{\"canal\":\"site\"}', '2026-08-06 12:00:00', '2026-08-06 12:00:00')"
);

$edited = catalog_draft_save($db, 1, [
    'optimized_title' => 'Título de teste.',
    'optimized_description' => 'Descrição revisada antes da aprovação.',
    'bullet_points' => "Benefício principal\nCompatibilidade confirmada",
    'seo_keywords' => 'teste, catálogo',
    'marketing_hooks' => 'Confira agora',
    'meta_title' => 'Título de teste.',
    'meta_description' => 'Descrição de busca revisada.',
]);

assert_same('Título de teste.', $edited['optimized_title'], 'O ponto final solicitado não foi persistido no título.');
assert_true(str_ends_with((string) $edited['optimized_title'], '.'), 'O título salvo deve terminar com ponto final.');
assert_same('pending', $edited['status'], 'A edição não pode aprovar ou publicar automaticamente o item.');
assert_same(null, $edited['error_message'], 'A edição deve limpar o erro técnico anterior.');
assert_same(null, $edited['publication_summary_json'], 'A edição deve limpar o resumo de publicação anterior.');
assert_same(null, $edited['published_at'], 'A edição não pode manter data de publicação.');
assert_same(
    ['Benefício principal', 'Compatibilidade confirmada'],
    json_decode((string) $edited['bullet_points_json'], true),
    'Os bullet points não foram persistidos corretamente.'
);

$threw = false;
try {
    catalog_draft_save($db, 1, [
        'optimized_title' => '   ',
        'optimized_description' => 'Descrição válida.',
    ]);
} catch (InvalidArgumentException) {
    $threw = true;
}
assert_true($threw, 'Título vazio deve ser recusado antes da gravação.');

fwrite(STDOUT, "OK: título editado para 'Título de teste.'; read-back confirmado; status permaneceu pending; nenhum canal foi publicado.\n");
