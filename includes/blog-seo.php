<?php
declare(strict_types=1);

function sv_blog_seo_origin(): string
{
    return 'https://shopvivaliz.com.br';
}

function sv_blog_seo_truncate(string $value, int $max): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';
    if ($value === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') <= $max) return $value;
    if (!function_exists('mb_strlen') && strlen($value) <= $max) return $value;

    $cut = function_exists('mb_substr')
        ? mb_substr($value, 0, $max, 'UTF-8')
        : substr($value, 0, $max);
    $cut = preg_replace('/\s+\S*$/u', '', $cut) ?: $cut;
    return rtrim($cut, " \t\n\r\0\x0B,;:-|");
}

function sv_blog_seo_title(string $value): string
{
    return sv_blog_seo_truncate($value, 60);
}

function sv_blog_seo_description(string $meta, string $excerpt = '', string $title = ''): string
{
    $meta = preg_replace('/\s+/u', ' ', trim($meta)) ?: '';
    $excerpt = preg_replace('/\s+/u', ' ', trim($excerpt)) ?: '';
    $title = preg_replace('/\s+/u', ' ', trim($title)) ?: '';

    $candidate = $meta !== '' ? $meta : $excerpt;
    $length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
    if ($length < 110) {
        $base = $candidate !== '' ? rtrim($candidate, '. ') . '. ' : '';
        $subject = $title !== '' ? 'sobre ' . $title : 'sobre este tema';
        $candidate = $base . 'Veja orientações práticas ' . $subject . ' e escolha com mais segurança na ShopVivaliz.';
    }

    return sv_blog_seo_truncate($candidate, 155);
}
