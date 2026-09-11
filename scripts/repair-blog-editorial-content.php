<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/blog-editorial-autopilot.php';

$options = array_slice($argv, 1);
$apply = in_array('--apply', $options, true);
$dryRun = in_array('--dry-run', $options, true) || !$apply;
if ($apply && in_array('--dry-run', $options, true)) {
    fwrite(STDERR, "Use apenas --dry-run ou --apply.\n");
    exit(2);
}

$backupDir = '';
foreach ($options as $option) {
    if (str_starts_with($option, '--backup-dir=')) {
        $backupDir = trim(substr($option, strlen('--backup-dir=')));
    }
}

if ($apply) {
    if ($backupDir === '' || !is_dir($backupDir) || !is_writable($backupDir)) {
        fwrite(STDERR, "--apply exige --backup-dir=<diretorio gravavel>.\n");
        exit(2);
    }
    $resolvedBackupDir = realpath($backupDir);
    if ($resolvedBackupDir === false) {
        fwrite(STDERR, "Diretorio de backup invalido.\n");
        exit(2);
    }
    $publicRoot = realpath(dirname(__DIR__));
    if ($publicRoot !== false && str_starts_with($resolvedBackupDir . DIRECTORY_SEPARATOR, $publicRoot . DIRECTORY_SEPARATOR)) {
        fwrite(STDERR, "O backup nao pode ficar dentro da arvore publica da aplicacao.\n");
        exit(2);
    }
    $backupDir = $resolvedBackupDir;
}

$targets = [];
foreach (sv_blog_editorial_agenda() as $weekday => $titles) {
    foreach ($titles as $title) {
        $slug = sv_blog_editorial_topic_slug($title);
        $targets[$slug] = [
            'title' => $title,
            'weekday' => $weekday,
            'article' => sv_blog_editorial_build_article($title, $weekday),
        ];
    }
}

foreach ($targets as $slug => $target) {
    $errors = sv_blog_editorial_validate_article($target['article']);
    if ($errors !== []) {
        fwrite(STDERR, "Artigo gerado invalido para {$slug}: " . implode(',', $errors) . "\n");
        exit(1);
    }
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Banco editorial indisponivel.\n");
    exit(1);
}

$result = $db->query('SELECT id,slug,title,excerpt,category,image_url,image_alt,content_json,faq_json,keywords_json,meta_title,meta_description,related_products_url,author,status,featured,reading_time,published_at,scheduled_at,created_at,updated_at FROM blog_articles ORDER BY id');
if (!$result) {
    fwrite(STDERR, "Falha ao ler artigos editoriais.\n");
    exit(1);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $slug = (string)($row['slug'] ?? '');
    if ($slug !== '' && isset($targets[$slug])) {
        $rows[$slug] = $row;
    }
}
$result->close();

$editableFields = [
    'title', 'excerpt', 'category', 'image_url', 'image_alt', 'content_json',
    'faq_json', 'keywords_json', 'meta_title', 'meta_description',
    'related_products_url', 'author', 'featured', 'reading_time',
];

$desiredFor = static function (array $article): array {
    return [
        'title' => (string)$article['title'],
        'excerpt' => (string)$article['excerpt'],
        'category' => (string)$article['category'],
        'image_url' => (string)$article['image_url'],
        'image_alt' => (string)$article['image_alt'],
        'content_json' => (string)json_encode($article['content'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'faq_json' => (string)json_encode($article['faq'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'keywords_json' => (string)json_encode($article['keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'meta_title' => (string)$article['meta_title'],
        'meta_description' => (string)$article['meta_description'],
        'related_products_url' => (string)$article['related_products_url'],
        'author' => (string)$article['author'],
        'featured' => !empty($article['featured']) ? 1 : 0,
        'reading_time' => (int)$article['reading_time'],
    ];
};

$changedSlugs = [];
$unchanged = 0;
foreach ($rows as $slug => $row) {
    $desired = $desiredFor($targets[$slug]['article']);
    $different = false;
    foreach ($editableFields as $field) {
        $actual = in_array($field, ['featured', 'reading_time'], true)
            ? (int)($row[$field] ?? 0)
            : (string)($row[$field] ?? '');
        if ($actual !== $desired[$field]) {
            $different = true;
            break;
        }
    }
    if ($different) {
        $changedSlugs[] = $slug;
    } else {
        $unchanged++;
    }
}

$summary = [
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'agenda_targets' => count($targets),
    'database_targets' => count($rows),
    'changed' => count($changedSlugs),
    'unchanged' => $unchanged,
    'missing_from_database' => count($targets) - count($rows),
];

if ($dryRun) {
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

$backupPayload = [
    'created_at_utc' => gmdate('c'),
    'purpose' => 'blog_editorial_repair_before_image',
    'rows' => array_values($rows),
];
$backupPath = rtrim($backupDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'blog-editorial-before-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
$backupJson = json_encode($backupPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($backupJson) || file_put_contents($backupPath, $backupJson . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Falha ao criar backup; nenhuma alteracao aplicada.\n");
    exit(1);
}
if (!chmod($backupPath, 0600)) {
    @unlink($backupPath);
    fwrite(STDERR, "Falha ao restringir permissoes do backup; nenhuma alteracao aplicada.\n");
    exit(1);
}

if ($changedSlugs === []) {
    $summary['backup'] = $backupPath;
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

$db->begin_transaction();
try {
    $sql = "UPDATE blog_articles SET
        title=?, excerpt=?, category=?, image_url=?, image_alt=?, content_json=?, faq_json=?, keywords_json=?,
        meta_title=?, meta_description=?, related_products_url=?, author=?, featured=?, reading_time=?, updated_at=CURRENT_TIMESTAMP
        WHERE slug=?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('prepare_failed');
    }

    $applied = 0;
    foreach ($changedSlugs as $slug) {
        $desired = $desiredFor($targets[$slug]['article']);
        $title = $desired['title'];
        $excerpt = $desired['excerpt'];
        $category = $desired['category'];
        $imageUrl = $desired['image_url'];
        $imageAlt = $desired['image_alt'];
        $contentJson = $desired['content_json'];
        $faqJson = $desired['faq_json'];
        $keywordsJson = $desired['keywords_json'];
        $metaTitle = $desired['meta_title'];
        $metaDescription = $desired['meta_description'];
        $relatedProductsUrl = $desired['related_products_url'];
        $author = $desired['author'];
        $featured = $desired['featured'];
        $readingTime = $desired['reading_time'];

        $stmt->bind_param(
            'ssssssssssssiis',
            $title,
            $excerpt,
            $category,
            $imageUrl,
            $imageAlt,
            $contentJson,
            $faqJson,
            $keywordsJson,
            $metaTitle,
            $metaDescription,
            $relatedProductsUrl,
            $author,
            $featured,
            $readingTime,
            $slug
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('update_failed:' . $slug);
        }
        $applied += $stmt->affected_rows > 0 ? 1 : 0;
    }
    $stmt->close();
    $db->commit();
    $summary['applied'] = $applied;
    $summary['backup'] = $backupPath;
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Reparo abortado e transacao revertida. Backup preservado em {$backupPath}.\n");
    exit(1);
}
