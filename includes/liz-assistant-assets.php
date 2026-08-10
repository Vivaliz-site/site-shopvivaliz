<?php
declare(strict_types=1);

function sv_render_liz_assistant_assets(): void
{
    if (!empty($GLOBALS['sv_liz_assistant_assets_included'])) {
        return;
    }

    $GLOBALS['sv_liz_assistant_assets_included'] = true;

    $cssPath = dirname(__DIR__) . '/public/assets/liz-assistant/liz-assistant.css';
    $jsPath = dirname(__DIR__) . '/public/assets/liz-assistant/liz-assistant.js';
    $cssVersion = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
    $jsVersion = is_file($jsPath) ? (string)filemtime($jsPath) : '1';

    echo '<link rel="stylesheet" href="/public/assets/liz-assistant/liz-assistant.css?v='
        . htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<script src="/public/assets/liz-assistant/liz-assistant.js?v='
        . htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
}

sv_render_liz_assistant_assets();
