<?php
declare(strict_types=1);

namespace Blocks;

class CountdownTimer extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'display' => 'flex',
            'gap' => '20px',
            'justifyContent' => 'center',
            'padding' => '20px',
            'backgroundColor' => '#fff3cd',
            'borderRadius' => '8px',
            'margin' => '20px 0'
        ];
    }

    public function render(): string {
        $endDate = $this->esc($this->prop('end_date', date('Y-m-d', strtotime('+7 days'))));
        $title = $this->esc($this->prop('title', 'Oferta válida por:'));

        return <<<HTML
<div class="countdown-timer-block" style="{$this->styleToString()}" data-end-date="$endDate">
    <div>
        <strong>$title</strong>
        <div class="countdown-display" style="font-size: 24px; font-weight: bold; color: #d97706; margin-top: 10px;">
            <span class="days">0</span>d <span class="hours">0</span>h <span class="minutes">0</span>m <span class="seconds">0</span>s
        </div>
    </div>
</div>
<script>
(function() {
    const block = document.querySelector('[data-end-date="$endDate"]');
    if (!block) return;

    function updateCountdown() {
        const endDate = new Date(block.dataset.endDate).getTime();
        const now = new Date().getTime();
        const distance = endDate - now;

        if (distance < 0) {
            block.style.display = 'none';
            return;
        }

        block.querySelector('.days').textContent = Math.floor(distance / (1000 * 60 * 60 * 24));
        block.querySelector('.hours').textContent = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        block.querySelector('.minutes').textContent = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        block.querySelector('.seconds').textContent = Math.floor((distance % (1000 * 60)) / 1000);
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
</script>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Countdown Timer',
            'description' => 'Temporizador de contagem regressiva',
            'icon' => '⏱️',
            'category' => 'Marketing',
            'props' => [
                'end_date' => ['type' => 'date', 'default' => date('Y-m-d', strtotime('+7 days'))],
                'title' => ['type' => 'text', 'default' => 'Oferta válida por:']
            ]
        ];
    }
}
