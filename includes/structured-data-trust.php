<?php
declare(strict_types=1);

require_once __DIR__ . '/google-shopping-feed-utils.php';

/**
 * Camada final de confiança para identificadores de Product JSON-LD.
 *
 * O SKU, preço, disponibilidade e URL continuam vindo do storefront. Já
 * brand/MPN/GTIN só podem sair no HTML público quando a mesma fonte usada pelo
 * feed do Merchant Center tiver um valor válido para aquele SKU.
 */

/** @return array<string,array{gtin:string,mpn:string,brand:string}> */
function svsdt_identifier_map(?array $override = null): array
{
    if ($override !== null) {
        return $override;
    }

    static $map = null;
    if ($map === null) {
        $map = svgf_catalog_identifier_map(dirname(__DIR__));
    }
    return $map;
}

/** @return array{gtin:string,mpn:string,brand:string} */
function svsdt_identifiers_for_sku(string $sku, array $map): array
{
    $sku = trim($sku);
    if ($sku === '') {
        return ['gtin' => '', 'mpn' => '', 'brand' => ''];
    }

    $candidate = $map[$sku] ?? null;
    if (!is_array($candidate)) {
        foreach ($map as $mapSku => $values) {
            if (strcasecmp(trim((string)$mapSku), $sku) === 0 && is_array($values)) {
                $candidate = $values;
                break;
            }
        }
    }

    if (!is_array($candidate)) {
        return ['gtin' => '', 'mpn' => '', 'brand' => ''];
    }

    $gtin = svgf_normalize_gtin($candidate['gtin'] ?? '');
    $mpn = svgf_scalar_text($candidate['mpn'] ?? '');
    $brand = svgf_scalar_text($candidate['brand'] ?? '');

    if ($mpn === '' || strlen($mpn) > 70 || strcasecmp($mpn, $sku) === 0) {
        $mpn = '';
    }
    if ($brand !== '' && strlen($brand) > 70) {
        $brand = '';
    }

    return ['gtin' => $gtin, 'mpn' => $mpn, 'brand' => $brand];
}

function svsdt_is_list(array $value): bool
{
    return function_exists('array_is_list')
        ? array_is_list($value)
        : array_keys($value) === range(0, count($value) - 1);
}

function svsdt_has_type(array $node, string $expected): bool
{
    $type = $node['@type'] ?? null;
    if (is_string($type)) {
        return strcasecmp($type, $expected) === 0;
    }
    if (!is_array($type)) {
        return false;
    }
    foreach ($type as $entry) {
        if (is_scalar($entry) && strcasecmp((string)$entry, $expected) === 0) {
            return true;
        }
    }
    return false;
}

function svsdt_normalize_node(mixed $node, array $identifierMap): mixed
{
    if (!is_array($node)) {
        return $node;
    }

    if (svsdt_is_list($node)) {
        $items = [];
        foreach ($node as $item) {
            $items[] = svsdt_normalize_node($item, $identifierMap);
        }
        return $items;
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = svsdt_normalize_node($value, $identifierMap);
        }
    }

    if (!svsdt_has_type($node, 'Product')) {
        return $node;
    }

    $sku = svgf_scalar_text($node['sku'] ?? '');
    $trusted = svsdt_identifiers_for_sku($sku, $identifierMap);

    // Nunca preservar identificadores produzidos por heurística ou copiados do
    // SKU. Primeiro remove todos; depois reintroduz apenas o que o Merchant
    // também reconhece como dado de fabricante válido.
    unset($node['brand'], $node['mpn'], $node['gtin'], $node['gtin8'], $node['gtin12'], $node['gtin13'], $node['gtin14']);

    if ($trusted['brand'] !== '') {
        $node['brand'] = ['@type' => 'Brand', 'name' => $trusted['brand']];
    }
    if ($trusted['mpn'] !== '') {
        $node['mpn'] = $trusted['mpn'];
    }
    if ($trusted['gtin'] !== '') {
        $node['gtin'] = $trusted['gtin'];
    }

    return $node;
}

function svsdt_sanitize_html(string $html, ?array $identifierMapOverride = null): string
{
    if ($html === '') {
        return $html;
    }

    $identifierMap = svsdt_identifier_map($identifierMapOverride);
    $updated = @preg_replace_callback(
        '~<script\s+type=["\']application/ld\+json["\']\s*>(.*?)</script>~si',
        static function (array $matches) use ($identifierMap): string {
            $raw = html_entity_decode(trim((string)($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return (string)$matches[0];
            }

            $normalized = svsdt_normalize_node($decoded, $identifierMap);
            if (!is_array($normalized)) {
                return (string)$matches[0];
            }

            try {
                $json = json_encode(
                    $normalized,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                    | JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                return (string)$matches[0];
            }

            return '<script type="application/ld+json">' . $json . '</script>';
        },
        $html
    );

    return is_string($updated) ? $updated : $html;
}

/**
 * Registrada antes do Public Trust Guard existente. Como buffers PHP fecham em
 * ordem LIFO, esta camada recebe o HTML já normalizado pelo guard principal e
 * faz a última validação dos identificadores de produto.
 */
function svsdt_register(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') {
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['index.php', 'produto.php', 'catalogo.php'], true)) {
        return;
    }

    $registered = true;
    ob_start(static fn(string $html): string => svsdt_sanitize_html($html));
}
