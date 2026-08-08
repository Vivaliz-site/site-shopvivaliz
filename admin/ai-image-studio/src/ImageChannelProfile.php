<?php

declare(strict_types=1);

/** @return array<string,array<string,mixed>> */
function ai_studio_channel_profiles(): array
{
    return [
        'site' => [
            'label' => 'ShopVivaliz',
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 12,
            'white_first' => false,
            'generation_guidance' => 'Prioritize a clean ecommerce composition that remains useful on desktop and mobile. White image should be catalog-clean; hero and ambient may be richer while keeping the exact product identity.',
            'audit_notes' => [
                'Imagem principal limpa e nitida; imagens secundarias podem demonstrar contexto real sem inventar itens incluidos.',
                'Gerar alt text e dados estruturados e responsabilidade da vitrine, nao da imagem rasterizada.',
            ],
        ],
        'ml' => [
            'label' => 'Mercado Livre',
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 12,
            'white_first' => true,
            'generation_guidance' => 'Optimize for Mercado Livre catalog clarity. For the white image use a pure white background, one exact product, no promotional text, borders, badges, watermarks or invented accessories. Keep the product large, centered and fully visible.',
            'audit_notes' => [
                'A imagem white deve ser aprovada primeiro e vira capa antes de hero/ambient.',
                'A galeria publicada e limitada pelo publisher a 12 imagens e preserva as imagens existentes apos as aprovadas.',
            ],
        ],
        'shopee' => [
            'label' => 'Shopee',
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 9,
            'white_first' => true,
            'generation_guidance' => 'Optimize for a Shopee mobile product card. Keep the exact product large and immediately recognizable. White image must be clean and free of text, badges, watermarks and unrelated props. Hero/ambient must not imply accessories or benefits not evidenced by the source photo/data.',
            'audit_notes' => [
                'A imagem white deve ser aprovada primeiro; o publisher usa no maximo 9 imagens.',
                'A fidelidade de marca, cor, formato, conectores e itens realmente incluidos tem prioridade sobre cenarios criativos.',
            ],
        ],
        'amazon' => [
            'label' => 'Amazon',
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 9,
            'white_first' => true,
            'generation_guidance' => 'Optimize for an Amazon-compliant main product image. For white: pure white background, only the exact product, product dominant in frame, sharp edges, realistic color, no text, badges, borders, watermarks or unsupported accessories. Secondary hero/ambient images remain factual and must not replace the main image.',
            'audit_notes' => [
                'A imagem white deve ser aprovada primeiro; Amazon recebe a white como main_product_image_locator.',
                'O publisher preserva a galeria existente e coloca as imagens aprovadas pelo Admin na frente.',
            ],
        ],
        'tiktok' => [
            'label' => 'TikTok Shop',
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 9,
            'white_first' => true,
            'generation_guidance' => 'Optimize for TikTok Shop product discovery. Generate a square, high-resolution image. White image is the clean cover: pure white background, exact product only, no text or promotional overlays. Hero/ambient should be visually engaging for mobile while remaining factual, unobstructed and consistent with the listing category and attributes.',
            'audit_notes' => [
                'A recomendacao operacional e 1600x1600; o Studio bloqueia arquivos tecnicamente abaixo de 1000px por lado.',
                'A white deve ser aprovada primeiro. O publisher usa no maximo 9 imagens e faz upload real antes do partial_edit.',
            ],
        ],
    ];
}

/** @return array<string,mixed> */
function ai_studio_channel_profile(string $channel): array
{
    $profiles = ai_studio_channel_profiles();
    return $profiles[$channel] ?? $profiles['site'];
}

function ai_studio_channel_guidance(string $channel, string $imageType): string
{
    $profile = ai_studio_channel_profile($channel);
    $extra = (string)($profile['generation_guidance'] ?? '');
    if ($imageType === 'white' && !empty($profile['white_first'])) {
        $extra .= ' This white image is intended to be the first marketplace image and must remain a neutral catalog cover.';
    }
    return trim($extra);
}
