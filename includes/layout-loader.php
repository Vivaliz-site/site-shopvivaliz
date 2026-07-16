<?php
declare(strict_types=1);

/**
 * Carrega configuração de layout salva pelo editor visual
 */

function sv_get_layout_config(): array
{
    $layoutFile = __DIR__ . '/../config/layout-config.json';

    $default = [
        'banners' => [
            [
                'id' => 'banner-1',
                'title' => 'Qualidade Garantida',
                'image' => 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 300"><defs><linearGradient id="g1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:%231976d2;stop-opacity:1" /><stop offset="100%" style="stop-color:%23f57c00;stop-opacity:1" /></linearGradient></defs><rect width="1200" height="300" fill="url(%23g1)"/><text x="600" y="150" font-size="48" font-weight="bold" text-anchor="middle" fill="white" dominant-baseline="middle">Produtos de Alta Qualidade</text></svg>',
                'link' => '#',
                'active' => true,
            ],
            [
                'id' => 'banner-2',
                'title' => 'Frete Rápido',
                'image' => 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 300"><defs><linearGradient id="g2" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:%23388e3c;stop-opacity:1" /><stop offset="100%" style="stop-color:%2300796b;stop-opacity:1" /></linearGradient></defs><rect width="1200" height="300" fill="url(%23g2)"/><text x="600" y="150" font-size="48" font-weight="bold" text-anchor="middle" fill="white" dominant-baseline="middle">Entrega Rápida em Todo Brasil</text></svg>',
                'link' => '#',
                'active' => true,
            ],
        ],
        'categories' => [
            'order' => ['utilidades', 'ferramentas', 'rodízios', 'banheiro', 'pet', 'jardim'],
            'visible' => ['utilidades', 'ferramentas', 'rodízios', 'banheiro', 'pet', 'jardim'],
        ],
        'products' => [
            'itemsPerPage' => 8,
            'autoPlay' => true,
            'autoPlayInterval' => 5000,
        ],
    ];

    if (!is_file($layoutFile)) {
        return $default;
    }

    $saved = json_decode((string)file_get_contents($layoutFile), true);
    return is_array($saved) ? array_merge($default, $saved) : $default;
}

function sv_get_active_banners(): array
{
    $config = sv_get_layout_config();
    return array_filter($config['banners'] ?? [], fn($b) => $b['active'] ?? true);
}

function sv_get_visible_categories(): array
{
    $config = sv_get_layout_config();
    return $config['categories']['visible'] ?? [];
}

function sv_get_categories_order(): array
{
    $config = sv_get_layout_config();
    return $config['categories']['order'] ?? [];
}

function sv_get_products_config(): array
{
    $config = sv_get_layout_config();
    return $config['products'] ?? ['itemsPerPage' => 8];
}
