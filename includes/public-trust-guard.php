<?php
declare(strict_types=1);

/**
 * Hardening da vitrine publica.
 *
 * A camada atua no HTML final para que crawlers, navegadores sem JavaScript e
 * conexoes lentas recebam somente prova social verificavel e JSON-LD valido.
 */

function svptg_replace_or_original(
    string $html,
    string $pattern,
    string $replacement,
    int $limit = 1
): string {
    $updated = @preg_replace($pattern, $replacement, $html, $limit);
    return is_string($updated) ? $updated : $html;
}

function svptg_is_list(array $value): bool
{
    return function_exists('array_is_list')
        ? array_is_list($value)
        : array_keys($value) === range(0, count($value) - 1);
}

function svptg_absolute_url(string $value, string $fallback = 'https://shopvivaliz.com.br/'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('~^https?://~i', $value) === 1) {
        return $value;
    }
    return 'https://shopvivaliz.com.br/' . ltrim($value, '/');
}

function svptg_product_placeholder_url(): string
{
    return 'https://shopvivaliz.com.br/images/product-placeholder.svg';
}

function svptg_types(array $node): array
{
    $type = $node['@type'] ?? [];
    if (is_string($type)) {
        return [$type];
    }
    if (is_array($type)) {
        return array_values(array_filter(array_map('strval', $type)));
    }
    return [];
}

/**
 * @return mixed|null Null indica que o no deve ser removido.
 */
function svptg_normalize_json_node(mixed $node, string $script): mixed
{
    if (!is_array($node)) {
        return $node;
    }

    if (svptg_is_list($node)) {
        $normalized = [];
        foreach ($node as $item) {
            $item = svptg_normalize_json_node($item, $script);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    $types = svptg_types($node);
    if ($script === 'index.php' && in_array('AggregateRating', $types, true)) {
        return null;
    }
    if ($script === 'produto.php' && in_array('FAQPage', $types, true)) {
        return null;
    }

    foreach ($node as $key => $value) {
        if ($key === '@graph' && is_array($value)) {
            $node[$key] = svptg_normalize_json_node($value, $script);
            continue;
        }

        if (in_array($key, ['url', 'item', 'mainEntityOfPage'], true) && is_string($value)) {
            // URL sem valor aponta para a raiz do site, nunca para uma imagem.
            $node[$key] = svptg_absolute_url($value);
            continue;
        }

        if ($key === 'image') {
            $images = is_array($value) ? $value : [$value];
            $images = array_values(array_unique(array_filter(array_map(
                static function (mixed $image): string {
                    $url = is_scalar($image) ? trim((string)$image) : '';
                    if ($url === '' || str_contains($url, 'logo-vivaliz-square')) {
                        return svptg_product_placeholder_url();
                    }
                    return svptg_absolute_url($url, svptg_product_placeholder_url());
                },
                $images
            ))));
            $node[$key] = $images;
            continue;
        }

        if (is_array($value)) {
            $normalized = svptg_normalize_json_node($value, $script);
            if ($normalized === null) {
                unset($node[$key]);
            } else {
                $node[$key] = $normalized;
            }
        }
    }

    if (in_array('Offer', $types, true)) {
        unset($node['priceValidUntil']);
        $price = isset($node['price']) && is_numeric($node['price'])
            ? (float)$node['price']
            : null;
        if ($price !== null && $price <= 0) {
            return null;
        }
    }

    if (in_array('Product', $types, true)) {
        if (isset($node['offers'])) {
            $offers = svptg_normalize_json_node($node['offers'], $script);
            if ($offers === null || $offers === []) {
                unset($node['offers']);
            } else {
                $node['offers'] = $offers;
            }
        }
        if (!isset($node['image']) || !is_array($node['image']) || $node['image'] === []) {
            $node['image'] = [svptg_product_placeholder_url()];
        }
    }

    return $node;
}

function svptg_fallback_json_ld(string $script): array
{
    $graph = [
        [
            '@type' => 'WebSite',
            '@id' => 'https://shopvivaliz.com.br/#website',
            'name' => 'Vivaliz',
            'url' => 'https://shopvivaliz.com.br/',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => 'https://shopvivaliz.com.br/catalogo?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ],
        [
            '@type' => 'Organization',
            '@id' => 'https://shopvivaliz.com.br/#organization',
            'name' => 'ShopVivaliz Ltda',
            'url' => 'https://shopvivaliz.com.br/',
            'logo' => 'https://shopvivaliz.com.br/images/logo-vivaliz.png',
        ],
    ];

    if ($script === 'produto.php') {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'url' => 'https://shopvivaliz.com.br' . (parse_url((string)($_SERVER['REQUEST_URI'] ?? '/produto'), PHP_URL_PATH) ?: '/produto'),
            'name' => 'Produto Vivaliz',
        ];
    }

    return ['@context' => 'https://schema.org', '@graph' => $graph];
}

function svptg_normalize_json_ld(string $html, string $script): string
{
    $updated = @preg_replace_callback(
        '~<script\s+type=["\']application/ld\+json["\']\s*>(.*?)</script>~si',
        static function (array $matches) use ($script): string {
            $raw = html_entity_decode(trim((string)($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = json_decode($raw, true);
            $normalized = is_array($decoded)
                ? svptg_normalize_json_node($decoded, $script)
                : svptg_fallback_json_ld($script);

            if (!is_array($normalized) || $normalized === []) {
                $normalized = svptg_fallback_json_ld($script);
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
                return $matches[0];
            }

            return '<script type="application/ld+json">' . $json . '</script>';
        },
        $html
    );

    return is_string($updated) ? $updated : $html;
}

function svptg_replace_product_placeholders(string $html): string
{
    $updated = @preg_replace_callback(
        '~<img\b[^>]*>~i',
        static function (array $matches): string {
            $tag = (string)$matches[0];
            if (stripos($tag, 'brand-logo') !== false || preg_match('~alt=["\']Vivaliz["\']~i', $tag) === 1) {
                return $tag;
            }
            if (stripos($tag, 'logo-vivaliz-square-v2.png') === false) {
                return $tag;
            }
            return str_replace(
                '/images/logo-vivaliz-square-v2.png',
                '/images/product-placeholder.svg',
                $tag
            );
        },
        $html
    );

    return is_string($updated) ? $updated : $html;
}

function svptg_sanitize_html(string $html, string $script): string
{
    if ($html === '') {
        return $html;
    }

    // O diretório /autodev é deliberadamente bloqueado no Apache. Nenhuma
    // página pública deve solicitar esse cliente de desenvolvimento.
    $html = svptg_replace_or_original(
        $html,
        '~\s*<script\s+src=["\']/autodev/client\.js(?:\?[^"\']*)?["\']\s*></script>\s*~i',
        "\n"
    );

    if ($script === 'index.php') {
        $verifiedMount = <<<'HTML'
<!-- Testimonials Section -->
<section class="home-testimonials home-section-shell" aria-labelledby="sv-testimonials-title">
  <div class="container">
    <div class="section-heading">
      <span class="section-eyebrow">Avaliações</span>
      <h2 id="sv-testimonials-title">Experiências de clientes</h2>
      <p>Avaliações enviadas por clientes e publicadas somente após moderação da equipe.</p>
    </div>
    <div class="testimonials-grid">
      <div class="sv-testimonial-empty">Carregando avaliações reais...</div>
    </div>
  </div>
</section>
HTML;

        $html = svptg_replace_or_original(
            $html,
            '~\s*<!-- Testimonials Section -->\s*<section\s+class="home-testimonials\b.*?</section>\s*~si',
            "\n" . $verifiedMount . "\n"
        );

        if (!str_contains($html, '/js/newsletter-v1.js')) {
            $html = str_replace(
                '</body>',
                '<script defer src="/js/newsletter-v1.js"></script>' . "\n</body>",
                $html
            );
        }
    }

    if ($script === 'produto.php') {
        $html = svptg_replace_or_original(
            $html,
            '~\s*<div\s+style="color:\s*#fbbf24;[^"]*">\s*★★★★★\s*<span[^>]*>\(4\.9/5\s*-\s*Excelente\)</span>\s*</div>\s*~si',
            "\n"
        );

        $html = svptg_replace_or_original(
            $html,
            '~\s*<!-- Customer Reviews Widget -->\s*<section\s+class="container\s+sv-reviews-section\b.*?</section>\s*~si',
            "\n"
        );

        // Limita a mudanca ao badge exato. Descricoes de produto que usem a
        // expressao permanecem intactas.
        $html = svptg_replace_or_original(
            $html,
            '~<span>\s*Garantia de Fábrica\s*</span>~iu',
            '<span>Garantia legal e do fabricante, quando aplicável</span>',
            2
        );
    }

    $html = svptg_replace_product_placeholders($html);
    return svptg_normalize_json_ld($html, $script);
}

function svptg_register(): void
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
    ob_start(static fn(string $html): string => svptg_sanitize_html($html, $script));
}
