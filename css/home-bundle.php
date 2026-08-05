<?php
declare(strict_types=1);

/**
 * Serve o CSS da home consolidado em UMA resposta HTTP, em vez de 16 tags
 * <link> separadas. Conteúdo e ordem são idênticos ao que cada arquivo
 * individual produzia antes — ver includes/asset-bundle-manifest.php para o
 * manifesto e a explicação de por que a ordem não pode mudar.
 *
 * Criado em 2026-08-05 para reduzir TTFB/peso da home (achado real: 19 CSS +
 * 11 JS separados, medidos via curl -w).
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/asset-bundle-manifest.php';

$files = sv_resolve_home_css_bundle_files($projectRoot);
$version = sv_home_css_bundle_version($projectRoot);

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . $version . '"');

// Cache-buster automático: se o navegador já tem essa versão exata (mesmo
// filemtime agregado), responde 304 sem reenviar os ~160KB.
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '' && trim($ifNoneMatch, '"') === $version) {
    http_response_code(304);
    exit;
}

foreach ($files as $file) {
    if (is_array($file)) {
        // Arquivo obrigatório ausente: registra no próprio CSS de saída em
        // vez de derrubar a página com fatal error.
        echo '/* AVISO: arquivo obrigatorio ausente: ' . $file['__missing__'] . " */\n";
        continue;
    }

    echo '/* ' . basename($file) . " */\n";
    $content = file_get_contents($file);
    if ($content !== false) {
        echo $content;
    }
    echo "\n";
}
