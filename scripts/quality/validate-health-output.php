<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$healthPath = $root . '/api/health.php';

ob_start();
require $healthPath;
$output = (string)ob_get_clean();

$decoded = json_decode($output, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "FALHOU: health.php nao retornou JSON valido\n");
    exit(1);
}

if (($decoded['ok'] ?? null) !== true) {
    fwrite(STDERR, "FALHOU: health.php retornou ok=false\n");
    exit(1);
}

$forbiddenPatterns = [
    '/OPENAI_API_KEY|GEMINI_API_KEY|GOOGLE_GEMINI_API_KEY|GOOGLE_IMAGEN_API_KEY/i',
    '/CLAUDE_API_KEY|ANTHROPIC_API_KEY|GROQ_API_KEY|OPENROUTER_API_KEY/i',
    '/MERCADOPAGO_ACCESS_TOKEN|MERCADOPAGO_WEBHOOK_SECRET|INFINITEPAY_WEBHOOK_SECRET/i',
    '/[A-Z]:\\\\[^"]+/i',
    '/"length"\s*:/i',
];

foreach ($forbiddenPatterns as $pattern) {
    if (preg_match($pattern, $output) === 1) {
        fwrite(STDERR, "FALHOU: health.php expoe metadado sensivel detectado por {$pattern}\n");
        exit(1);
    }
}

echo "COMPROVADO: health.php valido e sem metadados sensiveis\n";
