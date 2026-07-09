<?php
declare(strict_types=1);

namespace Blocks;

class PromoRibbon extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'background' => 'linear-gradient(135deg, #ff6b6b 0%, #ff8787 100%)',
            'color' => '#ffffff',
            'padding' => '12px 20px',
            'textAlign' => 'center',
            'fontSize' => '16px',
            'fontWeight' => '700',
            'margin' => '20px 0',
            'borderRadius' => '4px'
        ];
    }

    public function render(): string {
        $text = $this->esc($this->prop('text', '🎉 Promoção Especial!'));
        return "<div class=\"promo-ribbon-block\" style=\"{$this->styleToString()}\">$text</div>\n";
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Promo Ribbon',
            'description' => 'Faixa promocional entre seções',
            'icon' => '🎀',
            'category' => 'Marketing',
            'props' => [
                'text' => ['type' => 'text', 'default' => '🎉 Promoção Especial!']
            ]
        ];
    }
}
