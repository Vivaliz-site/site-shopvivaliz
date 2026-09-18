<?php
declare(strict_types=1);
$files = [
    __DIR__ . '/../api/liz-intelligent.php',
    __DIR__ . '/../includes/liz-testimonial-moderator.php',
    __DIR__ . '/../includes/liz-blog-comment-responder.php',
];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (!is_string($src)) { fwrite(STDERR, "cannot read $file\n"); exit(1); }
    if (!str_contains($src, 'OPENROUTER_API_KEY')) { fwrite(STDERR, "$file missing OpenRouter\n"); exit(1); }
}
$main = file_get_contents($files[0]);
$providerBlock = substr($main, strpos($main, '// 5. Configurar provedores e chaves'), 1200);
foreach (['OPENAI_API_KEY','ANTHROPIC_API_KEY', "['name' => 'gpt'", "['name' => 'claude'"] as $forbidden) {
    if (str_contains($providerBlock, $forbidden)) { fwrite(STDERR, "automatic Liz provider block contains forbidden provider: $forbidden\n"); exit(1); }
}
foreach (array_slice($files, 1) as $file) {
    $src = file_get_contents($file);
    $head = substr($src, 0, 2500);
    foreach (['OPENAI_API_KEY','ANTHROPIC_API_KEY'] as $forbidden) {
        if (str_contains($head, $forbidden)) { fwrite(STDERR, basename($file) . " automatic provider list contains $forbidden\n"); exit(1); }
    }
}
fwrite(STDOUT, "OK: automatic Liz flows are Gemini -> OpenRouter only.\n");
