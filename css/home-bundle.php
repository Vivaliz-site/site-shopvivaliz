<?php
declare(strict_types=1);

/**
 * Serve o CSS da home em duas partes ordenadas, evitando uma resposta CSS
 * individual acima de 150 KB. A ordem das regras continua a do manifesto; apenas
 * comentários não essenciais e linhas vazias são removidos da resposta para
 * reduzir bytes sem reescrever seletores, valores, calc(), strings ou URLs.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/asset-bundle-manifest.php';

$part = isset($_GET['part']) ? (int) $_GET['part'] : null;
if ($part !== 1 && $part !== 2) {
    $part = null; // compatibilidade para URLs antigas do bundle completo
}

$files = sv_resolve_home_css_bundle_files($projectRoot, $part);
$version = sv_home_css_bundle_version($projectRoot);
$etag = $version . ($part !== null ? '-p' . $part : '');

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . $etag . '"');

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '' && trim($ifNoneMatch, '"') === $etag) {
    http_response_code(304);
    exit;
}

/**
 * Compactação deliberadamente conservadora.
 *
 * - preserva comentários de licença iniciados por /*!;
 * - não remove espaços dentro de declarações;
 * - não toca em strings, URLs, calc() ou custom properties;
 * - reduz apenas comentários CSS comuns e linhas totalmente vazias.
 */
function sv_home_css_compact(string $css): string
{
    $css = str_replace(["\r\n", "\r"], "\n", $css);
    $css = preg_replace('~/\*(?!\!)[\s\S]*?\*/~', '', $css) ?? $css;
    $css = preg_replace('/^[\t ]*\n/m', '', $css) ?? $css;
    return trim($css) . "\n";
}

foreach ($files as $file) {
    if (is_array($file)) {
        // Arquivo obrigatório ausente: registra no próprio CSS de saída em
        // vez de derrubar a página com fatal error.
        echo '/* AVISO: arquivo obrigatorio ausente: ' . $file['__missing__'] . " */\n";
        continue;
    }

    $content = file_get_contents($file);
    if ($content !== false) {
        echo sv_home_css_compact($content);
    }
}
