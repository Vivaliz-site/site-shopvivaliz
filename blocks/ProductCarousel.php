<?php
declare(strict_types=1);

namespace Blocks;

class ProductCarousel extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'display' => 'grid',
            'gridAutoFlow' => 'column',
            'gap' => '16px',
            'overflowX' => 'auto',
            'padding' => '20px',
            'width' => '100%',
            'minWidth' => '0'
        ];
    }

    public function render(): string {
        $limit = (int)$this->prop('limit', 12);
        return <<<HTML
<div class="product-carousel-block" style="{$this->styleToString()}">
    <!-- Produtos em carrossel, limite: $limit -->
    {{children}}
</div>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Product Carousel',
            'description' => 'Carrossel horizontal de produtos',
            'icon' => '🛒',
            'category' => 'Catálogo',
            'props' => [
                'limit' => ['type' => 'number', 'default' => 12, 'min' => 1, 'max' => 50]
            ]
        ];
    }
}
