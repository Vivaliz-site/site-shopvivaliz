<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/pdo-database.php';

/** @return list<string> */
function catalog_ai_env_key_pool(array $baseNames): array
{
    $keys = [];
    foreach ($baseNames as $baseName) {
        $list = trim((string)getenv($baseName . 'S'));
        if ($list !== '') {
            $decoded = json_decode($list, true);
            $candidates = is_array($decoded)
                ? $decoded
                : (preg_split('/[\r\n,;]+/', $list) ?: []);
            foreach ($candidates as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') $keys[] = $candidate;
            }
        }
        for ($index = 1; $index <= 10; $index++) {
            $candidate = trim((string)getenv($baseName . '_' . $index));
            if ($candidate !== '') $keys[] = $candidate;
        }
        $legacy = trim((string)getenv($baseName));
        if ($legacy !== '') $keys[] = $legacy;
    }
    return array_values(array_unique($keys));
}

define('CATALOG_AI_OPENAI_API_KEY', catalog_ai_env_key_pool(['OPENAI_API_KEY']));
$catalogGeminiBasePool = catalog_ai_env_key_pool(['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY']);
$catalogGeminiGoogleAliasPool = catalog_ai_env_key_pool(['GOOGLE_API_KEY', 'GOOGLE_IMAGEN_API_KEY']);
define(
    'CATALOG_AI_GOOGLE_GEMINI_API_KEY',
    array_values(array_unique(array_merge($catalogGeminiBasePool, $catalogGeminiGoogleAliasPool)))
);
define('CATALOG_AI_CLAUDE_API_KEY', catalog_ai_env_key_pool(['CLAUDE_API_KEY', 'ANTHROPIC_API_KEY']));
define('CATALOG_AI_OPENROUTER_API_KEY', catalog_ai_env_key_pool(['OPENROUTER_API_KEY']));
define('CATALOG_AI_GROQ_API_KEY', catalog_ai_env_key_pool(['GROQ_API_KEY']));

define('CATALOG_AI_OPENAI_MODEL', (string)(getenv('OPENAI_TEXT_MODEL') ?: 'gpt-5-nano'));
define('CATALOG_AI_GEMINI_MODEL', (string)(getenv('GOOGLE_GEMINI_MODEL') ?: 'gemini-flash-lite-latest'));
define('CATALOG_AI_CLAUDE_MODEL', (string)(getenv('CLAUDE_TEXT_MODEL') ?: 'claude-haiku-4-5-20251001'));
define('CATALOG_AI_OPENROUTER_MODEL', (string)(getenv('OPENROUTER_TEXT_MODEL') ?: 'openai/gpt-4o-mini'));
define('CATALOG_AI_GROQ_MODEL', (string)(getenv('GROQ_TEXT_MODEL') ?: 'llama-3.1-8b-instant'));
define('CATALOG_AI_OPENROUTER_API_BASE_URL', (string)(getenv('OPENROUTER_API_BASE_URL') ?: 'https://openrouter.ai/api/v1'));
define('CATALOG_AI_OPENROUTER_HTTP_REFERER', (string)(getenv('OPENROUTER_HTTP_REFERER') ?: 'https://shopvivaliz.com.br'));
define('CATALOG_AI_OPENROUTER_APP_TITLE', (string)(getenv('OPENROUTER_APP_TITLE') ?: 'ShopVivaliz'));
define('CATALOG_AI_GROQ_API_BASE_URL', (string)(getenv('GROQ_API_BASE_URL') ?: 'https://api.groq.com/openai/v1'));
define('CATALOG_AI_HTTP_TIMEOUT_SECONDS', max(60, (int)(getenv('CATALOG_AI_HTTP_TIMEOUT_SECONDS') ?: 90)));

function catalog_ai_db(): ?PDO
{
    return sv_pdo();
}

/** @return array<string,string> */
function catalog_ai_channels(): array
{
    return [
        'ml' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'amazon' => 'Amazon',
        'tiktok' => 'TikTok Shop',
        'site' => 'Site Próprio',
        'erp' => 'Olist / Tiny ERP',
    ];
}
