<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/pdo-database.php';
if (is_file(__DIR__ . '/../../config/bootstrap-env.php')) {
    require_once __DIR__ . '/../../config/bootstrap-env.php';
}
if (is_file(__DIR__ . '/../../includes/runtime-env-reader.php')) {
    require_once __DIR__ . '/../../includes/runtime-env-reader.php';
}

/** @return list<string> */
function ai_studio_env_key_pool(array $baseNames): array
{
    $keys = [];
    foreach ($baseNames as $baseName) {
        $list = trim((string)getenv($baseName . 'S'));
        if ($list !== '') {
            $decoded = json_decode($list, true);
            $candidates = is_array($decoded) ? $decoded : (preg_split('/[\r\n,;]+/', $list) ?: []);
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

define('AI_STUDIO_OPENAI_API_KEY', ai_studio_env_key_pool(['AI_STUDIO_OPENAI_API_KEY', 'OPENAI_API_KEY']));
define('AI_STUDIO_GOOGLE_IMAGEN_API_KEY', ai_studio_env_key_pool(['AI_STUDIO_GOOGLE_IMAGEN_API_KEY', 'GOOGLE_IMAGEN_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY']));
define('AI_STUDIO_GOOGLE_IMAGEN_PROJECT_ID', (string)(getenv('GOOGLE_IMAGEN_PROJECT_ID') ?: ''));
define('AI_STUDIO_GOOGLE_IMAGEN_LOCATION', (string)(getenv('GOOGLE_IMAGEN_LOCATION') ?: 'us-central1'));
define('AI_STUDIO_CLAUDE_API_KEY', ai_studio_env_key_pool(['AI_STUDIO_CLAUDE_API_KEY', 'CLAUDE_API_KEY', 'ANTHROPIC_API_KEY']));
define('AI_STUDIO_QROPE_API_KEY', ai_studio_env_key_pool(['AI_STUDIO_QROPE_API_KEY', 'QROPE_API_KEY']));
define('AI_STUDIO_QROPE_API_BASE_URL', (string)(getenv('QROPE_API_BASE_URL') ?: 'https://api.qrope.ai/v1'));
define('AI_STUDIO_GROQ_API_KEY', ai_studio_env_key_pool(['AI_STUDIO_GROQ_API_KEY', 'GROQ_API_KEY']));
define('AI_STUDIO_GROQ_API_BASE_URL', (string)(getenv('GROQ_API_BASE_URL') ?: 'https://api.groq.com/openai/v1'));
define('AI_STUDIO_GROQ_IMAGE_MODEL', (string)(getenv('GROQ_IMAGE_MODEL') ?: 'openai/gpt-oss-20b'));
define('AI_STUDIO_OPENROUTER_API_KEY', ai_studio_env_key_pool(['AI_STUDIO_OPENROUTER_API_KEY', 'OPENROUTER_API_KEY']));
define('AI_STUDIO_OPENROUTER_API_BASE_URL', (string)(getenv('OPENROUTER_API_BASE_URL') ?: 'https://openrouter.ai/api/v1'));
define('AI_STUDIO_OPENROUTER_HTTP_REFERER', (string)(getenv('OPENROUTER_HTTP_REFERER') ?: 'https://shopvivaliz.com.br'));
define('AI_STUDIO_OPENROUTER_APP_TITLE', (string)(getenv('OPENROUTER_APP_TITLE') ?: 'ShopVivaliz'));

define('AI_STUDIO_OPENAI_IMAGE_MODEL', (string)(getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-1'));
define('AI_STUDIO_GOOGLE_IMAGEN_MODEL', (string)(getenv('GOOGLE_IMAGEN_MODEL') ?: 'gemini-2.5-flash-image'));
define('AI_STUDIO_CLAUDE_MODEL', (string)(getenv('CLAUDE_MODEL') ?: 'claude-haiku-4-5-20251001'));

define('AI_STUDIO_STORAGE_DIR', __DIR__ . '/storage/staging/');
define('AI_STUDIO_STORAGE_URL_PREFIX', '/admin/ai-image-studio/storage/staging/');
define('AI_STUDIO_BASE_IMAGE_TMP_DIR', __DIR__ . '/storage/base-image-tmp/');
foreach ([AI_STUDIO_STORAGE_DIR, AI_STUDIO_BASE_IMAGE_TMP_DIR] as $directory) {
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log('[ai-image-studio] Falha ao criar diretório privado: ' . $directory);
    }
}

function ai_studio_db(): ?PDO
{
    return sv_pdo();
}
