<?php
declare(strict_types=1);

namespace Blocks;

class CardContainer extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'background' => '#ffffff',
            'borderRadius' => '10px',
            'boxShadow' => '0 2px 8px rgba(0,0,0,0.1)',
            'padding' => '24px',
            'margin' => '20px 0'
        ];
    }

    public function render(): string {
        return "<div class=\"card-container-block\" style=\"{$this->styleToString()}\">{{children}}</div>\n";
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Card Container',
            'description' => 'Caixa destacada com sombra',
            'icon' => '📋',
            'category' => 'Design',
            'props' => []
        ];
    }
}
