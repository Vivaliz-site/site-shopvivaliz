<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-editorial-autopilot.php';

$agenda = sv_blog_editorial_agenda();
if (count($agenda['monday'] ?? []) !== 12 || count($agenda['wednesday'] ?? []) !== 12 || count($agenda['friday'] ?? []) !== 12) {
    fwrite(STDERR, "Agenda editorial invalida.\n");
    exit(1);
}

$reference = new DateTimeImmutable('2026-07-29 09:30:00', new DateTimeZone('America/Sao_Paulo'));
$slots = sv_blog_editorial_upcoming_slots($reference, 4);
$expectedWeekdays = ['wednesday', 'friday', 'monday', 'wednesday'];
foreach ($expectedWeekdays as $index => $weekday) {
    if (($slots[$index]['weekday'] ?? '') !== $weekday) {
        fwrite(STDERR, "Sequencia de agenda invalida.\n");
        exit(1);
    }
}

$article = sv_blog_editorial_build_article('Como escolher uma caixa organizadora para cada ambiente', 'monday');
$errors = sv_blog_editorial_validate_article($article);
if ($errors !== []) {
    fwrite(STDERR, "Artigo automatico invalido: " . implode(',', $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "OK blog editorial autopilot smoke\n");
