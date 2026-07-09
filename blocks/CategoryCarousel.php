<?php
declare(strict_types=1);

namespace Blocks;

class CategoryCarousel extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'display' => 'grid',
            'gridAutoFlow' => 'column',
            'gap' => '14px',
            'overflowX' => 'auto',
            'scrollSnapType' => 'x mandatory',
            'padding' => '20px 0',
            'width' => '100%',
            'minWidth' => '0'
        ];
    }

    public function render(): string {
        $limit = (int)$this->prop('limit', 10);
        return <<<HTML
<div class="category-carousel-block" style="{$this->styleToString()}">
    <!-- Categorias serão carregadas dinamicamente -->
    <!-- Limite: $limit categorias -->
    {{children}}
</div>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Category Carousel',
            'description' => 'Carrossel horizontal de categorias',
            'icon' => '🎠',
            'category' => 'Catálogo',
            'props' => [
                'limit' => ['type' => 'number', 'default' => 10, 'min' => 1, 'max' => 50]
            ]
        ];
    }
}
