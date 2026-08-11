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
            'visual_strategy' => [
                'white' => 'Imagem de catalogo limpa para capa ou galeria tecnica, sem texto e sem cenario.',
                'hero' => 'Composicao premium para vitrine propria, com luz controlada e espaco para leitura do produto.',
                'ambient' => 'Contexto realista de uso, permitido ser mais rico, desde que nao sugira acessorios inclusos.',
                'cover' => 'Capa propria do site: imagem principal limpa para vitrine e SEO visual, sem poluicao.',
            ],
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
            'visual_strategy' => [
                'white' => 'Capa obrigatoriamente limpa: fundo branco puro, produto unico, grande, sem texto ou acessorios.',
                'hero' => 'Imagem secundaria ainda objetiva, com destaque para forma e escala, sem apelo promocional.',
                'ambient' => 'Contexto minimo e factual, sem insinuar compatibilidade ou itens que nao estejam no cadastro.',
                'cover' => 'Capa Mercado Livre: equivalente a imagem principal white, fundo branco puro e produto dominante.',
            ],
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
            'generation_guidance' => 'Optimize for a Shopee mobile product card. Keep the exact product large and immediately recognizable. Cover may use a commercial marketplace layout with simple generic visual seals only when they do not claim discounts, warranty, ratings, official status, shipping promises or unsupported benefits. White image remains clean and free of text, badges, watermarks and unrelated props. Hero/ambient must not imply accessories or benefits not evidenced by the source photo/data.',
            'visual_strategy' => [
                'white' => 'Capa para app mobile: produto reconhecivel de imediato, fundo branco e corte sem perda de identidade.',
                'hero' => 'Visual forte para scroll mobile, mas sem textos, selos, beneficios inventados ou poluicao.',
                'ambient' => 'Cena curta e pratica, centrada no produto real, sem objetos que confundam o que acompanha a compra.',
                'cover' => 'Capa Shopee: quadrada e forte para app; pode usar selos visuais genericos sem prometer desconto, frete, garantia, nota, oficialidade ou beneficio nao comprovado.',
            ],
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
            'visual_strategy' => [
                'white' => 'Imagem principal em padrao Amazon: fundo branco, somente o produto, cor e bordas fieis.',
                'hero' => 'Imagem secundaria informativa, sem substituir a main image e sem claims visuais nao comprovados.',
                'ambient' => 'Uso contextual discreto e factual, sem alterar escala, cor, embalagem ou componentes visiveis.',
                'cover' => 'Capa Amazon: main image estrita, fundo branco, somente produto real, sem texto ou acessorios.',
            ],
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
            'visual_strategy' => [
                'white' => 'Capa limpa para aprovacao e descoberta, produto central e legivel no mobile.',
                'hero' => 'Criativo comercial para feed mobile, com iluminacao mais atrativa e produto dominante.',
                'ambient' => 'Cena de uso visualmente engajante, mas factual e sem exagerar beneficio, escala ou compatibilidade.',
                'cover' => 'Capa TikTok Shop: produto central, limpa o suficiente para aprovacao e forte em leitura mobile.',
            ],
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
    $minimumSide = (int)($profile['minimum_side'] ?? 1000);
    $recommendedSide = (int)($profile['recommended_side'] ?? $minimumSide);
    $maxGallery = (int)($profile['max_gallery'] ?? 9);
    if ($extra !== '') {
        $extra .= ' ';
    }
    $strategy = is_array($profile['visual_strategy'] ?? null) ? $profile['visual_strategy'] : [];
    $typeStrategy = trim((string)($strategy[$imageType] ?? ''));
    if ($typeStrategy !== '') {
        $extra .= 'Channel and image-type strategy: ' . $typeStrategy . ' ';
    }
    $extra .= sprintf(
        'Technical target: at least %dx%d px, recommended %dx%d px, gallery max %d images.',
        $minimumSide,
        $minimumSide,
        $recommendedSide,
        $recommendedSide,
        $maxGallery
    );
    $extra .= ' Preserve the exact shape, proportions, color, finish, scale and number of visible parts from the source photo.';
    if (in_array($imageType, ['cover', 'white'], true) && !empty($profile['white_first'])) {
        $extra .= ' This cover image is intended to be the first marketplace image and must remain a neutral catalog cover.';
    }
    return trim($extra);
}
