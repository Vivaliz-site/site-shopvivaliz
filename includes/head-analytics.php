<?php
/**
 * 📊 Head Analytics - Insere tracking de Google Ads, GA4, Facebook Pixel, TikTok
 * Incluir antes de </head> em todas as páginas
 */

require_once __DIR__ . '/analytics-tracking.php';

// Renderizar tracking codes
echo $GLOBALS['analytics']->getTrackingCode();

$googleEventsFile = dirname(__DIR__) . '/js/shopvivaliz-google-events.js';
$googleEventsVersion = is_file($googleEventsFile) ? (string)filemtime($googleEventsFile) : '1';
echo "\n<script src=\"/js/shopvivaliz-google-events.js?v=" . htmlspecialchars($googleEventsVersion, ENT_QUOTES, 'UTF-8') . "\"></script>\n";

// Track page view automaticamente
if (function_exists('track_page_view')) {
    $title = $GLOBALS['page_title'] ?? 'Page';
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    track_page_view($title, $path);
}
?>
