<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$paths = [
    'index.php', 'sobre/index.php', 'termos/index.php', 'politica-privacidade/index.php',
    'politica-devolucoes/index.php', 'politica-entrega/index.php', 'faq/index.php', 'contato/index.php',
    'catalogo/index.php', 'carrinho.php', 'checkout.php', 'minha-conta/index.php', 'minha-conta/pedidos.php',
    'api/send-order-confirmation-email.php', 'api/emails/send-order-notification.php',
];
$forbidden = [
    '/\bdebug\b/i' => 'debug_language',
    '/\bmock\b/i' => 'mock_language',
    '/\bsimula[cç][aã]o\b/i' => 'simulation_language',
    '/equipe\s+comercial/i' => 'commercial_team_public_copy',
    '/confirma[cç][aã]o\s+manual\s+de\s+frete/i' => 'manual_shipping_confirmation',
    '/documenta[cç][aã]o\s+interna/i' => 'internal_doc_language',
    '/\bendpoint\b/i' => 'endpoint_language',
];
$allow = [
    'termos/index.php' => ['/administrador/i'],
];
$errors = [];
foreach ($paths as $rel) {
    $file = $root . '/' . $rel;
    if (!is_file($file)) {
        continue;
    }
    $text = file_get_contents($file);
    if (!is_string($text)) continue;
    foreach ($forbidden as $pattern => $code) {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $allowed = false;
            foreach (($allow[$rel] ?? []) as $allowedPattern) {
                if (preg_match($allowedPattern, $m[0][0])) $allowed = true;
            }
            if (!$allowed) {
                $errors[] = $rel . ':' . $code . ':' . trim($m[0][0]);
            }
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, "PUBLIC_COPY_QUALITY_FAILED\n" . implode("\n", $errors) . "\n");
    exit(1);
}
echo 'PUBLIC_COPY_QUALITY_OK checked=' . count($paths) . PHP_EOL;
