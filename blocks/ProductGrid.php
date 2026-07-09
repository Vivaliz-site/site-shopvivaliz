<?php
declare(strict_types=1);

namespace Blocks;

class ProductGrid extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'display' => 'grid',
            'gridTemplateColumns' => 'repeat(auto-fill, minmax(260px, 1fr))',
            'gap' => '20px',
            'padding' => '40px 20px'
        ];
    }

    public function render(): string {
        $columns = (int)$this->prop('columns', 4);
        $limit = (int)$this->prop('limit', 12);
        $category = $this->prop('category', '');

        // Construir grid columns responsivo
        $gridTemplate = "repeat(auto-fill, minmax(260px, 1fr))";
        if ($columns > 0) {
            $gridTemplate = "repeat($columns, 1fr)";
        }

        $categoryFilter = $category ? " (category: $category)" : '';

        return <<<HTML
<section class="product-grid-block" style="{$this->styleToString()}">
    <!-- Produtos serão carregados via AJAX ou template PHP -->
    <!-- Limite: $limit produtos $categoryFilter -->
    {{children}}
</section>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Product Grid',
            'description' => 'Grade estática de produtos',
            'icon' => '📦',
            'category' => 'Catálogo',
            'props' => [
                'columns' => ['type' => 'number', 'default' => 4, 'min' => 1, 'max' => 6],
                'limit' => ['type' => 'number', 'default' => 12, 'min' => 1, 'max' => 100],
                'category' => ['type' => 'text', 'default' => '']
            ]
        ];
    }
}
