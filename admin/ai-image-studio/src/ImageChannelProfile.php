<?php

declare(strict_types=1);

/**
 * Politica visual canonica por canal.
 *
 * Os campos operacionais abaixo sao compartilhados pela geracao, pela UI e
 * pela auditoria. Assim, a tela nao precisa adivinhar quais variantes fazem
 * sentido e o prompt recebe exatamente a mesma estrategia apresentada ao
 * administrador.
 *
 * @return array<string,array<string,mixed>>
 */
function ai_studio_channel_profiles(): array
{
    return [
        'site' => [
            'label' => 'ShopVivaliz',
            'channel_goal' => 'Criar uma galeria premium para conversao, SEO visual e leitura clara em desktop e mobile.',
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 12,
            'white_first' => false,
            'essential_types' => ['cover', 'hero'],
            'recommended_types' => ['cover', 'hero', 'ambient'],
            'variant_order' => ['cover', 'hero', 'ambient', 'white'],
            'decision_rationale' => 'No site proprio, capa limpa e hero premium geram a base da vitrine; ambientada agrega contexto sem substituir a imagem principal.',
            'generation_guidance' => 'Prioritize a clean ecommerce composition that remains useful on desktop and mobile. The cover must be immediately understandable, the hero may be more premium, and the ambient image may show a factual use context. Keep the exact product identity and do not imply accessories, compatibility or benefits that are not proven by the catalog.',
            'visual_strategy' => [
                'white' => 'Imagem tecnica limpa para galeria, fundo branco, sem texto, selo ou cenario.',
                'hero' => 'Composicao premium para vitrine propria, luz controlada, produto dominante e espaco visual equilibrado.',
                'ambient' => 'Contexto realista de uso, rico o suficiente para explicar o produto sem sugerir itens inclusos.',
                'cover' => 'Capa principal limpa, forte para miniatura e SEO visual, sem poluicao ou promessas comerciais.',
            ],
            'risk_rules' => [
                'Nao transformar a imagem em banner com texto promocional.',
                'Nao esconder detalhes, conectores, acabamento, embalagem ou partes visiveis do produto.',
                'Nao adicionar acessorios que possam ser interpretados como inclusos.',
            ],
            'approval_checks' => [
                'Produto reconhecivel em miniatura.',
                'Cores, proporcoes, marcas e partes visiveis fieis a foto real.',
                'Capa e hero possuem funcoes visuais diferentes, sem duplicacao inutil.',
            ],
            'audit_notes' => [
                'Imagem principal limpa e nitida; imagens secundarias podem demonstrar contexto real sem inventar itens incluidos.',
                'Alt text e dados estruturados pertencem a vitrine e nao devem ser rasterizados na imagem.',
            ],
        ],
        'ml' => [
            'label' => 'Mercado Livre',
            'channel_goal' => 'Maximizar clareza de catalogo e aprovacao usando uma capa branca estrita e imagens secundarias factuais.',
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 12,
            'white_first' => true,
            'essential_types' => ['white'],
            'recommended_types' => ['white', 'hero', 'ambient'],
            'variant_order' => ['white', 'hero', 'ambient', 'cover'],
            'decision_rationale' => 'Mercado Livre precisa primeiro de uma imagem white segura. Hero e ambientada so entram como apoio e nunca podem assumir o lugar da capa.',
            'generation_guidance' => 'Optimize for Mercado Livre catalog clarity. For the white image use a pure white background, one exact product, no promotional text, borders, badges, watermarks or invented accessories. Keep the product large, centered and fully visible. Secondary images must remain objective, factual and free from unsupported compatibility claims.',
            'visual_strategy' => [
                'white' => 'Capa obrigatoriamente limpa: fundo branco puro, produto unico, grande, centralizado e totalmente visivel.',
                'hero' => 'Imagem secundaria objetiva, destacando forma, acabamento e escala sem apelo promocional.',
                'ambient' => 'Contexto minimo e factual, sem insinuar compatibilidade, acessorios ou itens nao cadastrados.',
                'cover' => 'Capa equivalente a white: fundo branco puro, produto dominante e nenhuma sobreposicao.',
            ],
            'risk_rules' => [
                'Nao usar texto, preco, frete, desconto, selo, borda ou marca d agua.',
                'Nao inserir veiculo, equipamento ou acessorio que sugira compatibilidade nao comprovada.',
                'Nao recortar partes do produto nem alterar sua escala aparente.',
            ],
            'approval_checks' => [
                'White aprovada antes de qualquer hero ou ambientada.',
                'Fundo realmente branco e produto completamente visivel.',
                'Galeria preserva imagens existentes e respeita o limite do canal.',
            ],
            'audit_notes' => [
                'A imagem white deve ser aprovada primeiro e vira capa antes de hero/ambient.',
                'A galeria publicada e limitada a 12 imagens e preserva as imagens existentes depois das aprovadas.',
            ],
        ],
        'shopee' => [
            'label' => 'Shopee',
            'channel_goal' => 'Gerar capa forte para o card mobile e uma galeria escaneavel, sem claims ou selos enganosos.',
            'minimum_side' => 1000,
            'recommended_side' => 1200,
            'max_gallery' => 9,
            'white_first' => true,
            'essential_types' => ['cover', 'white'],
            'recommended_types' => ['cover', 'white', 'hero', 'ambient'],
            'variant_order' => ['cover', 'white', 'hero', 'ambient'],
            'decision_rationale' => 'Shopee exige leitura imediata no mobile. A capa pode ter composicao comercial limpa, enquanto a white oferece uma referencia tecnica segura.',
            'generation_guidance' => 'Optimize for a Shopee mobile product card. Keep the exact product large and immediately recognizable. Cover may use a clean commercial marketplace composition, but never claim discount, shipping, warranty, rating, official status or unsupported benefits. White remains text-free. Hero and ambient must not imply accessories or benefits not evidenced by the source.',
            'visual_strategy' => [
                'white' => 'Referencia tecnica em fundo branco, produto reconhecivel de imediato e corte sem perda de identidade.',
                'hero' => 'Visual forte para scroll mobile, sem texto promocional, claims, selos enganosos ou poluicao.',
                'ambient' => 'Cena curta e pratica, centrada no produto real, sem objetos que confundam o que acompanha a compra.',
                'cover' => 'Capa quadrada e forte para app, comercialmente atraente sem prometer desconto, frete, garantia ou oficialidade.',
            ],
            'risk_rules' => [
                'Nao rasterizar preco, desconto, cupom, frete, avaliacao, garantia ou selo de loja oficial.',
                'Nao reduzir o produto para abrir espaco para decoracao sem funcao.',
                'Nao alterar cor, embalagem, quantidade ou itens visiveis.',
            ],
            'approval_checks' => [
                'Produto legivel em miniatura de card mobile.',
                'Capa comercial e white tecnica nao sao duplicatas identicas.',
                'Nenhum elemento visual cria uma promessa comercial nao comprovada.',
            ],
            'audit_notes' => [
                'A white deve estar disponivel como referencia segura; o publisher usa no maximo 9 imagens.',
                'Fidelidade de marca, cor, formato, conectores e itens inclusos tem prioridade sobre criatividade.',
            ],
        ],
        'amazon' => [
            'label' => 'Amazon',
            'channel_goal' => 'Produzir main image estrita e imagens secundarias informativas, com fidelidade tecnica maxima.',
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 9,
            'white_first' => true,
            'essential_types' => ['white'],
            'recommended_types' => ['white', 'hero', 'ambient'],
            'variant_order' => ['white', 'hero', 'ambient', 'cover'],
            'decision_rationale' => 'Amazon depende de uma main image branca e estrita. Hero e ambientada devem complementar informacao sem introduzir claims ou substituir a capa.',
            'generation_guidance' => 'Optimize for an Amazon-compliant main product image. For white: pure white background, only the exact product, product dominant in frame, sharp edges, realistic color, no text, badges, borders, watermarks or unsupported accessories. Secondary hero and ambient images remain factual and must not replace the main image.',
            'visual_strategy' => [
                'white' => 'Main image em fundo branco, somente o produto real, cor, bordas e quantidade de pecas fieis.',
                'hero' => 'Imagem secundaria informativa, limpa e tecnica, sem claims visuais nao comprovados.',
                'ambient' => 'Uso contextual discreto e factual, sem alterar escala, cor, embalagem ou componentes visiveis.',
                'cover' => 'Equivalente a main image estrita: fundo branco e somente o produto real.',
            ],
            'risk_rules' => [
                'Nao usar texto, infografico, badge, borda, marca d agua ou acessorio nao incluso na main image.',
                'Nao alterar numero de unidades, embalagem ou partes do produto.',
                'Nao usar cenario como imagem principal.',
            ],
            'approval_checks' => [
                'White aprovada primeiro e adequada para main_product_image_locator.',
                'Produto ocupa a maior parte do quadro sem corte.',
                'Imagens secundarias agregam contexto factual sem repetir a main image.',
            ],
            'audit_notes' => [
                'A imagem white deve ser aprovada primeiro e e enviada como main_product_image_locator.',
                'O publisher preserva a galeria existente e coloca as imagens aprovadas pelo Admin na frente.',
            ],
        ],
        'tiktok' => [
            'label' => 'TikTok Shop',
            'channel_goal' => 'Combinar capa segura com criativos mobile envolventes, sem perder fidelidade nem usar falsa urgencia.',
            'minimum_side' => 1000,
            'recommended_side' => 1600,
            'max_gallery' => 9,
            'white_first' => true,
            'essential_types' => ['white', 'hero'],
            'recommended_types' => ['white', 'hero', 'ambient'],
            'variant_order' => ['white', 'hero', 'ambient', 'cover'],
            'decision_rationale' => 'TikTok Shop precisa de uma capa limpa para aprovacao e de um hero forte para descoberta no feed; ambientada explica uso quando houver contexto factual.',
            'generation_guidance' => 'Optimize for TikTok Shop product discovery. Generate a square, high-resolution image. White is the clean cover: pure white background, exact product only, no text or promotional overlays. Hero and ambient should be visually engaging for mobile while remaining factual, unobstructed and consistent with the listing category and attributes.',
            'visual_strategy' => [
                'white' => 'Capa limpa para aprovacao e descoberta, produto central e legivel no mobile.',
                'hero' => 'Criativo para feed mobile com iluminacao atrativa, produto dominante e nenhuma promessa textual.',
                'ambient' => 'Cena de uso visualmente engajante, factual e sem exagerar beneficio, escala ou compatibilidade.',
                'cover' => 'Capa mobile limpa e forte, sem overlays promocionais ou elementos que escondam o produto.',
            ],
            'risk_rules' => [
                'Nao usar falsa urgencia, antes/depois, resultado garantido ou beneficio nao comprovado.',
                'Nao cobrir o produto com texto, sticker ou efeito de feed.',
                'Nao alterar forma, cor, quantidade ou itens inclusos.',
            ],
            'approval_checks' => [
                'White segura e hero visualmente distinto, ambos fieis ao produto.',
                'Produto continua legivel em tela pequena.',
                'Ambientada nao inventa demonstracao, compatibilidade ou resultado.',
            ],
            'audit_notes' => [
                'O alvo operacional e 1600x1600; arquivos abaixo de 1000px por lado sao bloqueados.',
                'A white deve ser aprovada primeiro. O publisher usa no maximo 9 imagens.',
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

/** @return list<string> */
function ai_studio_channel_recommended_types(string $channel, bool $essentialOnly = false): array
{
    $profile = ai_studio_channel_profile($channel);
    $key = $essentialOnly ? 'essential_types' : 'recommended_types';
    $types = is_array($profile[$key] ?? null) ? array_map('strval', $profile[$key]) : [];
    return array_values(array_intersect(['cover', 'white', 'hero', 'ambient'], array_values(array_unique($types))));
}

/** @return array<string,mixed> */
function ai_studio_channel_public_profile(string $channel): array
{
    $profile = ai_studio_channel_profile($channel);
    return [
        'label' => (string)($profile['label'] ?? $channel),
        'channel_goal' => (string)($profile['channel_goal'] ?? ''),
        'minimum_side' => (int)($profile['minimum_side'] ?? 1000),
        'recommended_side' => (int)($profile['recommended_side'] ?? 1000),
        'max_gallery' => (int)($profile['max_gallery'] ?? 9),
        'white_first' => !empty($profile['white_first']),
        'essential_types' => ai_studio_channel_recommended_types($channel, true),
        'recommended_types' => ai_studio_channel_recommended_types($channel, false),
        'variant_order' => array_values(array_map('strval', (array)($profile['variant_order'] ?? []))),
        'decision_rationale' => (string)($profile['decision_rationale'] ?? ''),
        'visual_strategy' => is_array($profile['visual_strategy'] ?? null) ? $profile['visual_strategy'] : [],
        'risk_rules' => array_values(array_map('strval', (array)($profile['risk_rules'] ?? []))),
        'approval_checks' => array_values(array_map('strval', (array)($profile['approval_checks'] ?? []))),
        'audit_notes' => array_values(array_map('strval', (array)($profile['audit_notes'] ?? []))),
    ];
}

function ai_studio_channel_guidance(string $channel, string $imageType): string
{
    $profile = ai_studio_channel_profile($channel);
    $extra = trim((string)($profile['generation_guidance'] ?? ''));
    if ($extra !== '') {
        $extra .= ' ';
    }

    $goal = trim((string)($profile['channel_goal'] ?? ''));
    if ($goal !== '') {
        $extra .= 'Channel objective: ' . $goal . ' ';
    }

    $strategy = is_array($profile['visual_strategy'] ?? null) ? $profile['visual_strategy'] : [];
    $typeStrategy = trim((string)($strategy[$imageType] ?? ''));
    if ($typeStrategy !== '') {
        $extra .= 'Channel and image-type strategy: ' . $typeStrategy . ' ';
    }

    $risks = array_values(array_filter(array_map('trim', array_map('strval', (array)($profile['risk_rules'] ?? [])))));
    if ($risks !== []) {
        $extra .= 'Channel-specific prohibitions: ' . implode(' ', $risks) . ' ';
    }

    $checks = array_values(array_filter(array_map('trim', array_map('strval', (array)($profile['approval_checks'] ?? [])))));
    if ($checks !== []) {
        $extra .= 'The final image must pass these approval checks: ' . implode(' ', $checks) . ' ';
    }

    $minimumSide = (int)($profile['minimum_side'] ?? 1000);
    $recommendedSide = (int)($profile['recommended_side'] ?? $minimumSide);
    $maxGallery = (int)($profile['max_gallery'] ?? 9);
    $order = array_values(array_map('strval', (array)($profile['variant_order'] ?? [])));
    $extra .= sprintf(
        'Technical target: at least %dx%d px, recommended %dx%d px, gallery max %d images.',
        $minimumSide,
        $minimumSide,
        $recommendedSide,
        $recommendedSide,
        $maxGallery
    );
    if ($order !== []) {
        $extra .= ' Recommended gallery order: ' . implode(' -> ', $order) . '.';
    }
    $extra .= ' Preserve the exact shape, proportions, color, finish, scale and number of visible parts from the source photo.';
    if (in_array($imageType, ['cover', 'white'], true) && !empty($profile['white_first'])) {
        $extra .= ' This cover image is intended to be the first marketplace image and must remain a neutral catalog cover.';
    }
    return trim($extra);
}
