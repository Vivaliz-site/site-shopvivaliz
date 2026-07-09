<?php
declare(strict_types=1);

namespace Blocks;

class Divider extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'borderTop' => '1px solid #e5e9f0',
            'margin' => '20px 0'
        ];
    }

    public function render(): string {
        return "<hr class=\"divider-block\" style=\"{$this->styleToString()}border: none;\" />\n";
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Divider',
            'description' => 'Linha divisória',
            'icon' => '─',
            'category' => 'Design',
            'props' => []
        ];
    }
}
