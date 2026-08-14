<?php
declare(strict_types=1);

function sv_render_liz_assistant_assets(): void
{
    if (!empty($GLOBALS['sv_liz_assistant_assets_included'])) {
        return;
    }

    $GLOBALS['sv_liz_assistant_assets_included'] = true;

    $root = dirname(__DIR__);
    $cssPath = $root . '/public/assets/liz-assistant/liz-assistant.css';
    $jsPath = $root . '/public/assets/liz-assistant/liz-assistant.js';
    $categoryRotationPath = $root . '/public/assets/storefront/category-image-rotation-v2.js';
    $identityPath = $root . '/public/assets/liz-assistant/liz-identity-v2.js';

    $assetVersion = static function (string $path): string {
        return is_file($path) ? (string)filemtime($path) : '1';
    };

    $cssVersion = $assetVersion($cssPath);
    $jsVersion = $assetVersion($jsPath);
    $categoryRotationVersion = $assetVersion($categoryRotationPath);
    $identityVersion = $assetVersion($identityPath);
    $cssHref = '/public/assets/liz-assistant/liz-assistant.css?v=' . rawurlencode($cssVersion);

    // The Liz widget is not above-the-fold content. Loading its stylesheet as a
    // render-blocking resource penalizes the storefront's first paint even when
    // the visitor never opens the assistant. Preload the bytes, then apply the
    // stylesheet asynchronously; noscript preserves the widget for JS-disabled
    // environments.
    echo '<link rel="preload" as="style" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8')
        . '" media="print" onload="this.media=\'all\';this.onload=null;">' . "\n";
    echo '<noscript><link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '"></noscript>' . "\n";

    // The category layer is intentionally synchronous here. This include is
    // emitted after the storefront content and before the legacy generic image
    // carousel, so it can claim category cards and prevent two timers from
    // competing for the same image element.
    echo '<script src="/public/assets/storefront/category-image-rotation-v2.js?v='
        . htmlspecialchars($categoryRotationVersion, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

    echo '<script src="/public/assets/liz-assistant/liz-assistant.js?v='
        . htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    echo '<script src="/public/assets/liz-assistant/liz-identity-v2.js?v='
        . htmlspecialchars($identityVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
}

sv_render_liz_assistant_assets();