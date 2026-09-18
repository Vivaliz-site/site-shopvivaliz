<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'api/liz-intelligent.php',
    'api/liz-general.php',
    'includes/liz-testimonial-moderator.php',
    'includes/liz-blog-comment-responder.php',
];

foreach ($files as $relative) {
    $text = (string)file_get_contents($root . '/' . $relative);
    foreach (['api.openai.com', 'api.anthropic.com', "env('OPENAI_API_KEY')", "env('ANTHROPIC_API_KEY')", "liz_env('OPENAI_API_KEY')", "liz_env('ANTHROPIC_API_KEY')"] as $forbidden) {
        if (str_contains($text, $forbidden)) {
            fwrite(STDERR, "FAIL {$relative}: forbidden direct paid provider marker {$forbidden}\n");
            exit(1);
        }
    }
    if (!str_contains($text, 'OPENROUTER_API_KEY')) {
        fwrite(STDERR, "FAIL {$relative}: OpenRouter fallback missing\n");
        exit(1);
    }
    if (!str_contains($text, 'GEMINI_API_KEY')) {
        fwrite(STDERR, "FAIL {$relative}: Gemini provider missing\n");
        exit(1);
    }
}

echo "PASS: public Liz surfaces are Gemini/OpenRouter only.\n";
