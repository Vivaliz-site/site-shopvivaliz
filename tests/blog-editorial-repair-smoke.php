<?php
declare(strict_types=1);

$script = __DIR__ . '/../scripts/repair-blog-editorial-content.php';
if (!is_file($script)) {
    fwrite(STDERR, "Script de reparo editorial ausente.\n");
    exit(1);
}

$source = (string)file_get_contents($script);
$required = [
    "PHP_SAPI !== 'cli'",
    '--dry-run',
    '--apply',
    '--backup-dir=',
    'sv_blog_editorial_agenda()',
    'begin_transaction()',
    'commit()',
    'rollback()',
    'chmod($backupPath, 0600)',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Contrato de reparo ausente: {$needle}\n");
        exit(1);
    }
}
foreach (['DELETE FROM blog_articles', 'DROP TABLE', 'TRUNCATE TABLE'] as $forbidden) {
    if (stripos($source, $forbidden) !== false) {
        fwrite(STDERR, "Operacao destrutiva proibida no reparo: {$forbidden}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK blog editorial repair smoke\n");
