<?php
/**
 * Head Analytics e recursos públicos compartilhados.
 */
require_once __DIR__ . '/analytics-tracking.php';
echo $GLOBALS['analytics']->getTrackingCode();

$googleEventsFile = dirname(__DIR__) . '/js/shopvivaliz-google-events.js';
$googleEventsVersion = is_file($googleEventsFile) ? (string)filemtime($googleEventsFile) : '1';
echo "\n<script src=\"/js/shopvivaliz-google-events.js?v=" . htmlspecialchars($googleEventsVersion, ENT_QUOTES, 'UTF-8') . "\"></script>\n";

$company = @include dirname(__DIR__) . '/config/company-profile.php';
$company = is_array($company) ? $company : [];
$whatsappRaw = (string)($company['social_media']['whatsapp'] ?? $company['phone'] ?? '');
$whatsappDigits = preg_replace('/\D+/', '', $whatsappRaw) ?: '';
$whatsappUrl = $whatsappDigits !== ''
    ? 'https://wa.me/' . $whatsappDigits . '?text=' . rawurlencode('Olá! Vim pelo site da ShopVivaliz e gostaria de atendimento.')
    : '/contato';
$config = ['whatsappUrl' => $whatsappUrl];
echo '<script>window.ShopVivalizPublicConfig=' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
$GLOBALS['sv_public_config_included'] = true;

$experienceCss = dirname(__DIR__) . '/css/public-experience-v1.css';
$experienceJs = dirname(__DIR__) . '/js/public-experience-v1.js';
$cssVersion = is_file($experienceCss) ? (string)filemtime($experienceCss) : '1';
$jsVersion = is_file($experienceJs) ? (string)filemtime($experienceJs) : '1';
echo '<link rel="stylesheet" href="/css/public-experience-v1.css?v=' . htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') . '">' . "\n";
echo '<script defer src="/js/public-experience-v1.js?v=' . htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
$GLOBALS['sv_public_experience_included'] = true;

if (function_exists('track_page_view')) {
    $title = $GLOBALS['page_title'] ?? 'Page';
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    track_page_view($title, $path);
}
?>
