<?php
declare(strict_types=1);

namespace Blocks;

/**
 * Banner herói com imagem e CTA
 */
class HeroBanner extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'backgroundSize' => 'cover',
            'backgroundPosition' => 'center',
            'minHeight' => '500px',
            'display' => 'flex',
            'alignItems' => 'center',
            'justifyContent' => 'center',
            'color' => '#ffffff',
            'textAlign' => 'center',
            'position' => 'relative'
        ];
    }

    public function render(): string {
        $title = $this->esc($this->prop('title', 'Bem-vindo'));
        $subtitle = $this->esc($this->prop('subtitle', ''));
        $ctaText = $this->esc($this->prop('cta_text', 'Explorar'));
        $ctaUrl = $this->esc($this->prop('cta_url', '/catalogo'));
        $image = $this->esc($this->prop('image', ''));
        $overlay = $this->prop('overlay_opacity', 0.3);

        $backgroundStyle = '';
        if ($image) {
            $backgroundStyle = "background-image: linear-gradient(rgba(0,0,0,$overlay), rgba(0,0,0,$overlay)), url('$image');";
        }

        $subtitleHtml = $subtitle ? "<p style=\"font-size: 18px; margin: 10px 0; opacity: 0.9;\">$subtitle</p>" : '';
        $ctaHtml = $ctaText ? "<a href=\"$ctaUrl\" class=\"btn btn-primary\" style=\"margin-top: 20px; display: inline-block; padding: 12px 24px; background: white; color: #173B63; text-decoration: none; border-radius: 6px; font-weight: 600;\">$ctaText</a>" : '';

        return <<<HTML
<section class="hero-banner" style="$backgroundStyle {$this->styleToString()}">
    <div class="hero-content" style="max-width: 600px; padding: 20px;">
        <h1 style="font-size: 48px; margin: 0; line-height: 1.2;">$title</h1>
        $subtitleHtml
        $ctaHtml
    </div>
</section>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Hero Banner',
            'description' => 'Banner herói com imagem de fundo e chamada à ação',
            'icon' => '🖼️',
            'category' => 'Marketing',
            'props' => [
                'title' => ['type' => 'text', 'default' => 'Bem-vindo'],
                'subtitle' => ['type' => 'text', 'default' => ''],
                'image' => ['type' => 'image', 'default' => ''],
                'cta_text' => ['type' => 'text', 'default' => 'Explorar'],
                'cta_url' => ['type' => 'url', 'default' => '/catalogo'],
                'overlay_opacity' => ['type' => 'number', 'default' => 0.3, 'min' => 0, 'max' => 1]
            ]
        ];
    }
}
