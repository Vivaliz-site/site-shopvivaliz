<?php
declare(strict_types=1);

namespace Blocks;

class DynamicSpacer extends BaseBlock {
    public function render(): string {
        $height = $this->prop('height', '40px');
        return "<div style=\"height: {$this->esc($height)};\"></div>\n";
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Dynamic Spacer',
            'description' => 'Espaçador com altura customizável',
            'icon' => '📏',
            'category' => 'Design',
            'props' => [
                'height' => ['type' => 'size', 'default' => '40px']
            ]
        ];
    }
}
