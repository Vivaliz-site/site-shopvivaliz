<?php
declare(strict_types=1);

namespace Blocks;

class ProductTitle extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'fontSize' => '32px',
            'fontWeight' => '700',
            'margin' => '0 0 16px 0',
            'lineHeight' => '1.2',
            'color' => '#111827'
        ];
    }

    public function render(): string {
        $title = $this->esc($this->prop('title', 'Produto'));
        $sku = $this->prop('sku', '');
        $skuHtml = $sku ? "<small style=\"font-size: 12px; color: #6b7280; display: block; margin-top: 8px;\">SKU: {$this->esc($sku)}</small>" : '';

        return <<<HTML
<h1 class="product-title" style="{$this->styleToString()}">
    $title
    $skuHtml
</h1>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Product Title',
            'description' => 'Título do produto (H1)',
            'icon' => '📝',
            'category' => 'Produto',
            'props' => [
                'title' => ['type' => 'text', 'default' => 'Nome do Produto'],
                'sku' => ['type' => 'text', 'default' => '']
            ]
        ];
    }
}
