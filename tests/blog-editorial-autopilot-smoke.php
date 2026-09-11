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

$legacyPatterns = [
    'Entenda o que avaliar em',
    'Aprenda um passo a passo simples para',
    'Veja ideias objetivas para',
    'O que observar antes de decidir',
    'Onde esse tipo de solução ajuda',
    'Como escolher com equilíbrio',
];
$cases = [
    ['Rodízio com trava ou sem trava: quando usar cada modelo', 'monday', 'comparison'],
    ['Como escolher uma caixa organizadora para cada ambiente', 'monday', 'buying_guide'],
    ['Como limpar ferragens sem danificar o acabamento', 'wednesday', 'maintenance'],
    ['Erros comuns ao instalar ganchos e suportes', 'wednesday', 'tutorial'],
    ['Ideias de organização para garagem e área de serviço', 'friday', 'project'],
];
$signatures = [];
foreach ($cases as [$title, $weekday, $expectedIntent]) {
    if (sv_blog_editorial_intent($title) !== $expectedIntent) {
        fwrite(STDERR, "Intent editorial incorreto para {$title}.\n");
        exit(1);
    }
    $candidate = sv_blog_editorial_build_article($title, $weekday);
    $candidateErrors = sv_blog_editorial_validate_article($candidate);
    if ($candidateErrors !== []) {
        fwrite(STDERR, "Artigo {$expectedIntent} invalido: " . implode(',', $candidateErrors) . "\n");
        exit(1);
    }
    $haystack = (string)($candidate['excerpt'] ?? '') . ' ' . implode(' ', array_column($candidate['content'] ?? [], 'heading'));
    foreach ($legacyPatterns as $pattern) {
        if (str_contains($haystack, $pattern)) {
            fwrite(STDERR, "Boilerplate editorial detectado: {$pattern}\n");
            exit(1);
        }
    }
    $signatures[] = implode('|', array_column($candidate['content'] ?? [], 'heading'));
}
if (count(array_unique($signatures)) !== count($cases)) {
    fwrite(STDERR, "Estruturas editoriais pouco variadas.\n");
    exit(1);
}

fwrite(STDOUT, "OK blog editorial autopilot smoke\n");
