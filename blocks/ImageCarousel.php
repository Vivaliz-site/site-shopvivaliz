<?php
declare(strict_types=1);

namespace Blocks;

class ImageCarousel extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'position' => 'relative',
            'width' => '100%',
            'maxWidth' => '100%',
            'margin' => '0 auto',
            'borderRadius' => '8px',
            'overflow' => 'hidden'
        ];
    }

    public function render(): string {
        $images = $this->prop('images', []);
        $autoplay = $this->prop('autoplay', true) ? 'true' : 'false';
        $interval = (int)$this->prop('interval', 5);
        $responsive = $this->prop('responsive', true) ? 'true' : 'false';

        if (!is_array($images) || empty($images)) {
            return "<!-- ImageCarousel: No images configured -->\n";
        }

        $slides = '';
        foreach ($images as $index => $image) {
            $slides .= "<img src=\"{$this->esc($image)}\" alt=\"Banner $index\" style=\"width: 100%; height: auto; display: none; object-fit: cover;\" class=\"carousel-slide\" data-index=\"$index\" loading=\"lazy\" />\n";
        }

        $dots = '';
        foreach ($images as $index => $image) {
            $active = $index === 0 ? ' style="background: #173B63;"' : '';
            $dots .= "<button class=\"carousel-dot\" data-index=\"$index\" aria-label=\"Slide $index\"$active style=\"width: 10px; height: 10px; border-radius: 50%; border: none; cursor: pointer; background: rgba(255,255,255,0.6); margin: 0 3px; transition: all 0.3s;\"></button>\n";
        }

        return <<<HTML
<div class="image-carousel-block" style="{$this->styleToString()}">
    <div class="carousel-container" style="position: relative; width: 100%; overflow: hidden; background: #f5f5f5;">
        $slides
        <button class="carousel-arrow prev" aria-label="Anterior" style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.4); color: white; border: none; padding: 10px 12px; cursor: pointer; font-size: 18px; border-radius: 4px; z-index: 10; display: none; @media (min-width: 768px) { display: block; }">‹</button>
        <button class="carousel-arrow next" aria-label="Próximo" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.4); color: white; border: none; padding: 10px 12px; cursor: pointer; font-size: 18px; border-radius: 4px; z-index: 10; display: none; @media (min-width: 768px) { display: block; }">›</button>
    </div>
    <div class="carousel-dots" style="text-align: center; padding: 10px; display: flex; justify-content: center; background: rgba(0,0,0,0.05);">
        $dots
    </div>
</div>

<script>
(function() {
    const carousel = document.querySelector('.image-carousel-block');
    if (!carousel) return;

    const slides = carousel.querySelectorAll('.carousel-slide');
    const dots = carousel.querySelectorAll('.carousel-dot');
    const prevBtn = carousel.querySelector('.carousel-arrow.prev');
    const nextBtn = carousel.querySelector('.carousel-arrow.next');
    const autoplay = $autoplay;
    const interval = $interval * 1000;

    let currentIndex = 0;
    let autoplayTimer = null;

    function showSlide(index) {
        slides.forEach((slide, i) => {
            slide.style.display = i === index ? 'block' : 'none';
        });
        dots.forEach((dot, i) => {
            dot.style.background = i === index ? '#173B63' : '#ccc';
        });
    }

    function nextSlide() {
        currentIndex = (currentIndex + 1) % slides.length;
        showSlide(currentIndex);
        restartAutoplay();
    }

    function prevSlide() {
        currentIndex = (currentIndex - 1 + slides.length) % slides.length;
        showSlide(currentIndex);
        restartAutoplay();
    }

    function restartAutoplay() {
        if (!autoplay) return;
        clearInterval(autoplayTimer);
        autoplayTimer = setInterval(nextSlide, interval);
    }

    if (prevBtn) prevBtn.addEventListener('click', prevSlide);
    if (nextBtn) nextBtn.addEventListener('click', nextSlide);

    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            currentIndex = index;
            showSlide(currentIndex);
            restartAutoplay();
        });
    });

    if (autoplay) {
        autoplayTimer = setInterval(nextSlide, interval);
    }
})();
</script>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Image Carousel',
            'description' => 'Carrossel de imagens/banners com setas e navegação',
            'icon' => '🎠',
            'category' => 'Marketing',
            'props' => [
                'images' => ['type' => 'array', 'default' => []],
                'autoplay' => ['type' => 'boolean', 'default' => true],
                'interval' => ['type' => 'number', 'default' => 5, 'min' => 1, 'max' => 30]
            ]
        ];
    }
}
