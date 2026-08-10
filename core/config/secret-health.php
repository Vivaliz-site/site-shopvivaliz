<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/runtime-env-reader.php';

function sv_secret_health_required_keys(): array
{
    return [
        'openai' => ['OPENAI_API_KEY'],
        'gemini' => ['GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'GOOGLE_IMAGEN_API_KEY'],
        'claude' => ['CLAUDE_API_KEY', 'ANTHROPIC_API_KEY'],
        'openrouter' => ['OPENROUTER_API_KEY'],
        'groq' => ['GROQ_API_KEY'],
        'mercadopago' => ['MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_WEBHOOK_SECRET'],
        'infinitepay' => ['INFINITEPAY_WEBHOOK_SECRET'],
    ];
}

function sv_secret_health_is_placeholder(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return true;
    }
    foreach (['changeme', 'change-me', 'replace', 'placeholder', 'your_', 'xxx', 'todo', 'example'] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }
    return false;
}

function sv_secret_health_mask_status(string $key): array
{
    $value = svre_value($key);
    $present = $value !== '';
    return [
        'present' => $present,
        'placeholder' => $present ? sv_secret_health_is_placeholder($value) : false,
        'length' => $present ? strlen($value) : 0,
    ];
}

function sv_secret_health_report(): array
{
    $groups = [];
    $hasPlaceholder = false;
    foreach (sv_secret_health_required_keys() as $group => $keys) {
        $keyStatuses = [];
        $hasUsable = false;
        foreach ($keys as $key) {
            $status = sv_secret_health_mask_status($key);
            $keyStatuses[$key] = $status;
            if (($status['placeholder'] ?? false) === true) {
                $hasPlaceholder = true;
            }
            if (($status['present'] ?? false) && !($status['placeholder'] ?? false)) {
                $hasUsable = true;
            }
        }
        $groups[$group] = [
            'configured' => $hasUsable,
            'ok' => !$hasPlaceholder,
            'keys' => $keyStatuses,
        ];
    }

    return [
        'ok' => !$hasPlaceholder,
        'groups' => $groups,
    ];
}
