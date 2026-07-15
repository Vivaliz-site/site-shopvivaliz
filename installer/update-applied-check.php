<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svi_root(): string
{
    return dirname(__DIR__);
}

function svi_version_payload(): array
{
    $file = svi_root() . '/config/shopvivaliz-version.php';
    if (is_file($file)) {
        $payload = require $file;
        if (is_array($payload)) {
            return $payload;
        }
    }

    return ['version' => '0.0.0', 'codename' => 'unknown', 'channel' => 'unknown'];
}

function svi_page(string $relativePath): string
{
    $path = svi_root() . '/' . ltrim($relativePath, '/');
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    return (string)file_get_contents($path);
}

function svi_has(string $haystack, string $needle): bool
{
    return $haystack !== '' && stripos($haystack, $needle) !== false;
}

$version = svi_version_payload();
$produto = svi_page('produto.php');
$checkout = svi_page('checkout.php');
$catalogo = svi_page('catalogo.php');

$checks = [
    'Produto com botao Comprar agora' => svi_has($produto, 'Comprar agora') || svi_has($catalogo, 'Comprar agora'),
    'Produto com campo CEP' => svi_has($produto, 'CEP') || svi_has($catalogo, 'CEP') || svi_has($checkout, 'name="cep"'),
    'Checkout com PIX' => svi_has($checkout, 'PIX'),
    'Checkout com boleto' => svi_has($checkout, 'boleto'),
    'Catalogo publico ativo' => is_file(svi_root() . '/api/catalog/products.php'),
    'Diagnostico Melhor Envio presente' => is_file(svi_root() . '/api/melhorenvio/diagnostic.php'),
    'Diagnostico Pagar.me presente' => is_file(svi_root() . '/api/pagarme/diagnostic.php'),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'version' => (string)($version['version'] ?? '0.0.0'),
    'codename' => (string)($version['codename'] ?? ''),
    'channel' => (string)($version['channel'] ?? ''),
    'generated_at' => date('c'),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
