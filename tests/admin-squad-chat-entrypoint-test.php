<?php
declare(strict_types=1);

$root = (string)(getenv('SHOPVIVALIZ_TEST_ROOT') ?: dirname(__DIR__));
$file = $root . '/admin/index.php';
$html = is_file($file) ? (string)file_get_contents($file) : '';

if ($html === '') {
    fwrite(STDERR, "admin index unavailable\n");
    exit(1);
}

$href = '/admin/ai-squad.php';
$occurrences = substr_count($html, $href);

if ($occurrences < 2) {
    fwrite(STDERR, "Squad Chat admin entry points missing: found {$occurrences}\n");
    exit(1);
}

if (strpos($html, '🤖 Squad Chat') === false && strpos($html, '>Squad Chat<') === false) {
    fwrite(STDERR, "Squad Chat label missing\n");
    exit(1);
}

echo "ADMIN_SQUAD_CHAT_ENTRYPOINT_TEST=PASS\n";
