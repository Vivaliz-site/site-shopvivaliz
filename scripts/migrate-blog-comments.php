<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

$sqlFile = __DIR__ . '/../database/migrations/20260728_create_blog_comments.sql';
$sql = file_get_contents($sqlFile);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "Migração de comentários não encontrada.\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();
if (!$db->multi_query($sql)) {
    fwrite(STDERR, "Falha ao iniciar migração: {$db->error}\n");
    exit(1);
}

do {
    if ($result = $db->store_result()) {
        $result->free();
    }
    if ($db->errno) {
        fwrite(STDERR, "Falha na migração: {$db->error}\n");
        exit(1);
    }
} while ($db->more_results() && $db->next_result());

fwrite(STDOUT, "OK blog comments migration\n");