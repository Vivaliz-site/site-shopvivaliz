<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/asset-bundle-manifest.php';

function sv_css_test_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

sv_css_test_assert(
    function_exists('sv_home_css_bundle_parts'),
    'O manifesto da home deve expor partes ordenadas para evitar um unico CSS acima de 150 KB.'
);

$full = sv_home_css_bundle_manifest();
$parts = sv_home_css_bundle_parts();

sv_css_test_assert(count($parts) === 2, 'A home deve usar exatamente duas partes CSS ordenadas.');
sv_css_test_assert(array_merge(...$parts) === $full, 'As partes devem reconstruir exatamente o manifesto original e preservar a cascata.');

foreach ($parts as $index => $entries) {
    $bytes = 0;
    foreach ($entries as $entry) {
        $path = $root . '/' . $entry['path'];
        sv_css_test_assert(is_file($path), 'Arquivo CSS ausente no teste: ' . $entry['path']);
        $css = (string) file_get_contents($path);
        $css = str_replace(["\r\n", "\r"], "\n", $css);
        $css = preg_replace('~/\*(?!\!)[\s\S]*?\*/~', '', $css) ?? $css;
        $css = preg_replace('/^[\t ]*\n/m', '', $css) ?? $css;
        $bytes += strlen(trim($css) . "\n");
    }
    sv_css_test_assert($bytes < 150000, 'Parte ' . ($index + 1) . ' excede 150 KB: ' . $bytes . ' bytes.');
}

fwrite(STDOUT, "COMPROVADO: bundle CSS da home dividido em duas partes abaixo de 150 KB sem alterar a ordem.\n");
