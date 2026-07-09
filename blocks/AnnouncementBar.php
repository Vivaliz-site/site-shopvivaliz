<?php
declare(strict_types=1);

namespace Blocks;

class AnnouncementBar extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'backgroundColor' => '#ff0055',
            'color' => '#ffffff',
            'padding' => '12px 20px',
            'textAlign' => 'center',
            'fontSize' => '14px',
            'fontWeight' => '600'
        ];
    }

    public function render(): string {
        $text = $this->esc($this->prop('text', '📢 Announcement'));
        $link = $this->prop('link', '');
        $closeButton = $this->prop('close_button', false) ? '1' : '0';

        $linkHtml = $link ? " href=\"" . $this->esc($link) . "\"" : '';

        return <<<HTML
<div class="announcement-bar" style="{$this->styleToString()}" data-closeable="$closeButton">
    <a $linkHtml style="color: inherit; text-decoration: none; display: inline-block;">
        $text
    </a>
</div>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Announcement Bar',
            'description' => 'Barra de anúncio no topo da página',
            'icon' => '📢',
            'category' => 'Marketing',
            'props' => [
                'text' => ['type' => 'text', 'default' => '📢 Announcement'],
                'link' => ['type' => 'url', 'default' => ''],
                'close_button' => ['type' => 'boolean', 'default' => true]
            ]
        ];
    }
}
