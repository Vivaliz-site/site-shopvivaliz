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
    $correctionCssPath = dirname(__DIR__) . '/public/assets/liz-assistant/liz-assistant-corrections-v1.css';
    $correctionJsPath = dirname(__DIR__) . '/public/assets/liz-assistant/liz-assistant-corrections-v1.js';
    $cssVersion = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
    $jsVersion = is_file($jsPath) ? (string)filemtime($jsPath) : '1';
    $correctionCssVersion = is_file($correctionCssPath) ? (string)filemtime($correctionCssPath) : '1';
    $correctionJsVersion = is_file($correctionJsPath) ? (string)filemtime($correctionJsPath) : '1';
    $cssHref = '/public/assets/liz-assistant/liz-assistant.css?v=' . rawurlencode($cssVersion);
    $correctionCssHref = '/public/assets/liz-assistant/liz-assistant-corrections-v1.css?v=' . rawurlencode($correctionCssVersion);

    // The Liz widget is not above-the-fold content. Loading its stylesheet as a
    // render-blocking resource penalizes the storefront's first paint even when
    // the visitor never opens the assistant. Preload the bytes, then apply the
    // stylesheet asynchronously; noscript preserves the widget for JS-disabled
    // environments.
    echo '<link rel="preload" as="style" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8')
        . '" media="print" onload="this.media=\'all\';this.onload=null;">' . "\n";
    echo '<noscript><link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '"></noscript>' . "\n";
    echo '<link rel="stylesheet" href="' . htmlspecialchars($correctionCssHref, ENT_QUOTES, 'UTF-8')
        . '" media="print" onload="this.media=\'all\';this.onload=null;">' . "\n";
    echo '<script src="/public/assets/liz-assistant/liz-assistant.js?v='
        . htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    echo '<script src="/public/assets/liz-assistant/liz-assistant-corrections-v1.js?v='
        . htmlspecialchars($correctionJsVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
}

sv_render_liz_assistant_assets();
