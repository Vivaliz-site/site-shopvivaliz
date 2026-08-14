<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/ImageChannelProfile.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$profiles = [];
foreach (array_keys(ai_studio_channel_profiles()) as $channel) {
    $profiles[$channel] = ai_studio_channel_public_profile($channel);
}

$providers = [
    'openrouter' => [
        'label' => 'OpenRouter',
        'role' => 'Editor visual com imagem de referencia',
        'default_model' => trim((string)(getenv('OPENROUTER_IMAGE_MODEL') ?: 'openai/gpt-image-1')),
        'pixel_editor' => true,
        'prompt_optimizer' => false,
    ],
    'groq' => [
        'label' => 'Groq + editor visual',
        'role' => 'Groq melhora o prompt; OpenRouter, OpenAI ou Gemini edita os pixels',
        'default_model' => '',
        'pixel_editor' => false,
        'prompt_optimizer' => true,
    ],
    'openai' => [
        'label' => 'OpenAI',
        'role' => 'Edicao direta da foto real',
        'default_model' => defined('AI_STUDIO_OPENAI_IMAGE_MODEL') ? (string)AI_STUDIO_OPENAI_IMAGE_MODEL : 'gpt-image-1',
        'pixel_editor' => true,
        'prompt_optimizer' => false,
    ],
    'google' => [
        'label' => 'Gemini',
        'role' => 'Edicao direta da foto real',
        'default_model' => defined('AI_STUDIO_GOOGLE_IMAGEN_MODEL') ? (string)AI_STUDIO_GOOGLE_IMAGEN_MODEL : 'gemini-2.5-flash-image',
        'pixel_editor' => true,
        'prompt_optimizer' => false,
    ],
    'claude' => [
        'label' => 'Claude + editor visual',
        'role' => 'Claude melhora a cena; OpenAI, Gemini ou OpenRouter edita os pixels',
        'default_model' => '',
        'pixel_editor' => false,
        'prompt_optimizer' => true,
    ],
    'huggingface' => [
        'label' => 'Hugging Face (gratuito)',
        'role' => 'Edicao direta da foto real via Inference API gratuita',
        'default_model' => defined('AI_STUDIO_HUGGINGFACE_IMAGE_MODEL') ? (string)AI_STUDIO_HUGGINGFACE_IMAGE_MODEL : 'timbrooks/instruct-pix2pix',
        'pixel_editor' => true,
        'prompt_optimizer' => false,
    ],
];

echo json_encode([
    'success' => true,
    'profiles' => $profiles,
    'providers' => $providers,
    'variant_labels' => [
        'cover' => 'Capa do canal',
        'white' => 'Branco tecnico',
        'hero' => 'Hero comercial',
        'ambient' => 'Ambientada / lifestyle',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
