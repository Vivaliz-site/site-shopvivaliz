<?php
declare(strict_types=1);

/**
 * Resolve a URL de imagem mais confiavel para a release atual.
 *
 * O pipeline historico gravava site_url em /uploads/olist, inclusive em
 * caminhos de deploy antigos. Em releases imutaveis esses arquivos podem nao
 * existir mais. Por isso fontes remotas originais do ERP/Tiny tem prioridade.
 * URLs locais da propria ShopVivaliz so sao aceitas quando o arquivo existe
 * fisicamente na release atual.
 */
function svsis_resolve_image_url(array $row, string $root): string
{
    $remoteCandidates = [
        $row['original_url_olist'] ?? '',
        $row['image_url'] ?? '',
        $row['original_url'] ?? '',
    ];

    foreach ($remoteCandidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && preg_match('~^https?://~i', $candidate)) {
            return $candidate;
        }
    }

    foreach (['site_url', 'local_url'] as $field) {
        $candidate = trim((string)($row[$field] ?? ''));
        if ($candidate === '') continue;

        if (str_starts_with($candidate, '/')) {
            $path = parse_url($candidate, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && is_file($root . $path)) {
                return 'https://shopvivaliz.com.br' . $path;
            }
            continue;
        }

        if (!preg_match('~^https?://~i', $candidate)) continue;

        $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?? ''));
        $path = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');
        if (in_array($host, ['shopvivaliz.com.br', 'www.shopvivaliz.com.br'], true)) {
            if ($path !== '' && is_file($root . $path)) {
                return 'https://shopvivaliz.com.br' . $path;
            }
            continue;
        }

        return $candidate;
    }

    return '';
}
