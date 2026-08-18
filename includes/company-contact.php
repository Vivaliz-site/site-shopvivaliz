<?php
declare(strict_types=1);

function sv_company_profile(): array
{
    static $profile = null;
    if (is_array($profile)) return $profile;
    $loaded = @include dirname(__DIR__) . '/config/company-profile.php';
    return $profile = is_array($loaded) ? $loaded : [];
}

function sv_company_whatsapp_digits(): string
{
    $configured = preg_replace('/\D+/', '', (string)(getenv('LOJA_WHATSAPP') ?: '')) ?: '';
    if ($configured !== '') return $configured;
    $profile = sv_company_profile();
    return preg_replace('/\D+/', '', (string)($profile['social_media']['whatsapp'] ?? $profile['mobile'] ?? $profile['phone'] ?? '')) ?: '';
}
