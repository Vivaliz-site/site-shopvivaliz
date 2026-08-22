<?php
declare(strict_types=1);

function sv_blog_editorial_agenda(): array
{
    return [
        'monday' => [
            'Como escolher uma caixa organizadora para cada ambiente',
            'Rodízio com trava ou sem trava: quando usar cada modelo',
            'Cadeado para área externa: o que observar antes de comprar',
            'Comparativo entre caixas plásticas, cestos e organizadores',
            'Como escolher suporte, gancho e fixador com segurança',
            'Rodízios para móveis pesados: cuidados com carga e piso',
            'Guia de compra de cadeados para portões, armários e malas',
            'Buchas e parafusos: como escolher conforme o tipo de parede',
            'Ferragens para móveis: dobradiças, puxadores e acessórios',
            'Como escolher acessórios para banheiro e áreas úmidas',
            'Comparativo entre tipos de organizadores para estoque doméstico',
            'Como escolher ferramentas para presentear com utilidade',
        ],
        'wednesday' => [
            'Como guardar ferramentas sem perder peças pequenas',
            'Como evitar que parafusos e buchas fiquem soltos',
            'Como limpar ferragens sem danificar o acabamento',
            'Como medir espaços antes de comprar acessórios para casa',
            'Cuidados para aumentar a durabilidade de ferramentas manuais',
            'Como evitar ferrugem em peças metálicas guardadas',
            'Como organizar produtos de limpeza com segurança',
            'Quando trocar uma ferramenta desgastada',
            'Como reduzir ruído em rodízios e móveis com rodas',
            'Erros comuns ao instalar ganchos e suportes',
            'Como conservar cadeados que ficam pouco tempo em uso',
            'Revisão mensal da casa: o que conferir em ferragens e acessórios',
        ],
        'friday' => [
            '7 itens simples para deixar a lavanderia mais organizada',
            'Produtos úteis para organizar armários pequenos',
            'Kit básico para pequenos reparos domésticos',
            'Ideias de organização para garagem e área de serviço',
            'Acessórios que ajudam na rotina de limpeza e manutenção',
            'Como montar uma caixa de ferramentas para apartamento',
            'Itens práticos para deixar a cozinha mais funcional',
            'Organizadores para quarto: como escolher sem acumular excessos',
            'Soluções simples para guardar itens de uso frequente',
            'Lista de compras para organizar a casa no fim de semana',
            'Produtos para facilitar pequenos consertos em casa',
            'Guia rápido de produtos úteis para começar uma casa nova',
        ],
    ];
}

function sv_blog_editorial_upcoming_slots(DateTimeImmutable $fromLocal, int $count): array
{
    $count = max(1, $count);
    $slots = [];
    $cursor = $fromLocal;
    $publicationHour = 10;

    while (count($slots) < $count) {
        $candidate = $cursor->setTime($publicationHour, 0, 0);
        $weekday = (int)$candidate->format('N');

        if (!in_array($weekday, [1, 3, 5], true) || $candidate <= $cursor) {
            $cursor = $cursor->modify('+1 day')->setTime(0, 0, 0);
            continue;
        }

        $slots[] = [
            'weekday' => sv_blog_editorial_weekday_key($weekday),
            'scheduled_at_local' => $candidate,
            'scheduled_at_utc' => $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
        $cursor = $candidate->modify('+1 minute');
    }

    return $slots;
}

function sv_blog_editorial_weekday_key(int $isoWeekday): string
{
    return match ($isoWeekday) {
        1 => 'monday',
        3 => 'wednesday',
        5 => 'friday',
        default => '',
    };
}

function sv_blog_editorial_topic_slug(string $title): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    if (!is_string($normalized) || $normalized === '') {
        $normalized = $title;
    }

    $slug = strtolower($normalized);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

function sv_blog_editorial_build_article(string $title, string $weekdayKey): array
{
    $profile = sv_blog_editorial_topic_profile($title);
    $slug = sv_blog_editorial_topic_slug($title);
    $excerpt = sv_blog_editorial_excerpt($title, $profile['category'], $weekdayKey);
    $metaTitle = sv_blog_editorial_truncate($title . ' | ShopVivaliz', 60);
    $metaDescription = sv_blog_editorial_truncate(
        $excerpt . ' Veja critérios de escolha, uso seguro e produtos relacionados na ShopVivaliz.',
        155
    );

    return [
        'slug' => $slug,
        'title' => $title,
        'excerpt' => $excerpt,
        'category' => $profile['category'],
        'image_url' => '/public/assets/blog/' . $slug . '.jpg',
        'image_alt' => $title,
        'content' => sv_blog_editorial_sections($title, $profile, $weekdayKey),
        'faq' => sv_blog_editorial_faq($title, $profile, $weekdayKey),
        'keywords' => sv_blog_editorial_keywords($title, $profile),
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'related_products_url' => '/catalogo?busca=' . rawurlencode($profile['search_term']),
        'author' => 'Equipe ShopVivaliz',
        'featured' => false,
        'reading_time' => $weekdayKey === 'monday' ? 6 : 5,
    ];
}

function sv_blog_editorial_validate_article(array $article): array
{
    $errors = [];

    foreach (['slug', 'title', 'excerpt', 'category', 'meta_title', 'meta_description', 'related_products_url'] as $field) {
        if (!isset($article[$field]) || trim((string)$article[$field]) === '') {
            $errors[] = 'missing_' . $field;
        }
    }

    $content = $article['content'] ?? [];
    if (!is_array($content) || count($content) < 3) {
        $errors[] = 'invalid_content_sections';
    }

    $metaTitleLength = function_exists('mb_strlen') ? mb_strlen((string)($article['meta_title'] ?? ''), 'UTF-8') : strlen((string)($article['meta_title'] ?? ''));
    $metaDescriptionLength = function_exists('mb_strlen') ? mb_strlen((string)($article['meta_description'] ?? ''), 'UTF-8') : strlen((string)($article['meta_description'] ?? ''));
    if ($metaTitleLength > 60) $errors[] = 'meta_title_too_long';
    if ($metaDescriptionLength < 110) $errors[] = 'meta_description_too_short';
    if ($metaDescriptionLength > 155) $errors[] = 'meta_description_too_long';

    $faq = $article['faq'] ?? [];
    if (!is_array($faq) || count($faq) < 2) {
        $errors[] = 'invalid_faq';
    }

    return $errors;
}

function sv_blog_editorial_topic_profile(string $title): array
{
    $patterns = [
        [
            'match' => ['rodizio', 'rodízio', 'rodizios', 'rodízios', 'roda', 'rodas'],
            'category' => 'Rodízios',
            'image_url' => '/public/assets/category-images/cat-rodizios.jpg',
            'search_term' => 'rodizio',
        ],
        [
            'match' => ['cadeado', 'cadeados', 'fechadura', 'fechaduras', 'seguranca', 'segurança'],
            'category' => 'Cadeados',
            'image_url' => '/public/assets/category-images/cat-ferragens.jpg',
            'search_term' => 'cadeado',
        ],
        [
            'match' => ['bucha', 'buchas', 'parafuso', 'parafusos', 'fixador', 'fixadores', 'suporte', 'suportes', 'gancho', 'ganchos'],
            'category' => 'Fixadores',
            'image_url' => '/public/assets/category-images/cat-ferragens.jpg',
            'search_term' => 'fixadores',
        ],
        [
            'match' => ['ferragem', 'ferragens', 'dobradica', 'dobradiça', 'puxador', 'puxadores', 'metalica', 'metálica', 'metalicas', 'metálicas'],
            'category' => 'Ferragens',
            'image_url' => '/public/assets/category-images/cat-ferragens.jpg',
            'search_term' => 'ferragens',
        ],
        [
            'match' => ['ferramenta', 'ferramentas', 'reparo', 'reparos', 'conserto', 'consertos'],
            'category' => 'Ferramentas',
            'image_url' => '/public/assets/category-images/cat-ferramentas.jpg',
            'search_term' => 'ferramentas',
        ],
        [
            'match' => ['organizar', 'organiza', 'organizacao', 'organização', 'armario', 'armário', 'cozinha', 'lavanderia', 'garagem', 'estoque', 'caixa', 'quarto', 'limpeza'],
            'category' => 'Organização',
            'image_url' => '/public/assets/category-images/cat-organizacao.jpg',
            'search_term' => 'organizacao',
        ],
    ];

    $haystack = sv_blog_editorial_lower($title);
    foreach ($patterns as $pattern) {
        foreach ($pattern['match'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return [
                    'category' => $pattern['category'],
                    'image_url' => $pattern['image_url'],
                    'search_term' => $pattern['search_term'],
                ];
            }
        }
    }

    return [
        'category' => 'Utilidades domésticas',
        'image_url' => '/public/assets/category-images/cat-organizacao.jpg',
        'search_term' => 'utilidades',
    ];
}

function sv_blog_editorial_excerpt(string $title, string $category, string $weekdayKey): string
{
    return match ($weekdayKey) {
        'monday' => "Entenda o que avaliar em {$title}, compare opções com segurança e escolha soluções adequadas na categoria {$category}.",
        'wednesday' => "Aprenda um passo a passo simples para {$title}, evitando erros comuns e preservando segurança e praticidade na rotina.",
        default => "Veja ideias objetivas para {$title}, com foco em uso real, organização da casa e produtos relacionados da ShopVivaliz.",
    };
}

function sv_blog_editorial_sections(string $title, array $profile, string $weekdayKey): array
{
    return match ($weekdayKey) {
        'monday' => [
            [
                'heading' => 'O que observar antes de decidir',
                'paragraphs' => [
                    "Antes de aplicar {$title}, vale medir o espaço, entender a rotina de uso e confirmar o tipo de material ou ambiente envolvido.",
                    "Na categoria {$profile['category']}, a compra costuma funcionar melhor quando a escolha parte da necessidade real, não apenas do preço ou da aparência.",
                ],
            ],
            [
                'heading' => 'Como comparar opções com segurança',
                'paragraphs' => [
                    'Compare capacidade, acabamento, forma de instalação, resistência esperada e facilidade de manutenção antes de fechar a escolha.',
                    'Quando houver dúvida sobre carga, fixação, ambiente úmido ou uso frequente, siga a especificação do fabricante e evite adaptações improvisadas.',
                ],
                'list' => [
                    'verificar medidas e tipo de aplicação',
                    'conferir material e acabamento',
                    'validar compatibilidade com o ambiente',
                    'confirmar cuidados básicos de instalação e uso',
                ],
            ],
            [
                'heading' => 'Quando vale buscar ajuda técnica',
                'paragraphs' => [
                    "Se {$title} envolver risco estrutural, elétrica, peso elevado ou fixação crítica, o mais seguro é contar com instalação qualificada.",
                    'A orientação profissional evita danos no produto, no ambiente e reduz risco de acidentes em situações que pedem validação técnica.',
                ],
            ],
        ],
        'wednesday' => [
            [
                'heading' => 'Por que esse cuidado faz diferença',
                'paragraphs' => [
                    "Aplicar {$title} de forma consistente ajuda a reduzir desgaste, bagunça e retrabalho no dia a dia.",
                    "Pequenos ajustes frequentes costumam ser mais eficientes do que correções grandes depois que o problema já apareceu.",
                ],
            ],
            [
                'heading' => 'Passo a passo prático',
                'paragraphs' => [
                    'Comece separando os itens envolvidos, conferindo medidas e observando se há sujeira, folga, umidade ou esforço excessivo no uso atual.',
                    'Depois ajuste, limpe ou reorganize de forma simples, sempre respeitando o tipo de material e a recomendação do fabricante quando houver.',
                ],
                'list' => [
                    'identificar a causa principal do incômodo',
                    'organizar ou limpar antes de substituir peças',
                    'testar a solução em uso real',
                    'repetir a revisão periodicamente',
                ],
            ],
            [
                'heading' => 'Erros comuns para evitar',
                'paragraphs' => [
                    'Forçar encaixes, usar produto inadequado, ignorar limites de peso ou limpar com material abrasivo são falhas recorrentes.',
                    "Se {$title} não resolver com um ajuste simples, interrompa a tentativa improvisada e reavalie a necessidade com mais cuidado.",
                ],
            ],
        ],
        default => [
            [
                'heading' => 'Onde esse tipo de solução ajuda',
                'paragraphs' => [
                    "No contexto de {$title}, produtos simples podem melhorar organização, praticidade e acesso aos itens usados com frequência.",
                    'A melhor escolha costuma ser a que resolve um problema específico da rotina sem adicionar volume ou complexidade desnecessária.',
                ],
            ],
            [
                'heading' => 'Como escolher com equilíbrio',
                'paragraphs' => [
                    "Antes de comprar, confirme o espaço disponível, o volume de uso e a compatibilidade com a categoria {$profile['category']}.",
                    'Prefira soluções fáceis de manter, com instalação proporcional ao ambiente e utilidade clara para a casa.',
                ],
                'list' => [
                    'priorizar uso real e frequência',
                    'evitar excesso de acessórios',
                    'buscar produtos compatíveis com o ambiente',
                    'combinar organização com manutenção simples',
                ],
            ],
            [
                'heading' => 'Produtos relacionados que costumam combinar',
                'paragraphs' => [
                    "Quem busca {$title} normalmente também se beneficia de acessórios complementares, desde que façam sentido para a rotina e para o espaço disponível.",
                    'Usar o catálogo como referência ajuda a comparar opções próximas sem depender de improvisos ou compras por impulso.',
                ],
            ],
        ],
    };
}

function sv_blog_editorial_faq(string $title, array $profile, string $weekdayKey): array
{
    return match ($weekdayKey) {
        'monday' => [
            [
                'question' => "Como saber se {$title} é a escolha certa?",
                'answer' => 'Confirme medidas, tipo de uso, ambiente e limites do produto antes da compra. Quando houver risco técnico, procure instalação qualificada.',
            ],
            [
                'question' => 'Vale escolher apenas pelo menor preço?',
                'answer' => "Nao. Em {$profile['category']}, durabilidade, compatibilidade e seguranca costumam pesar mais do que uma economia imediata.",
            ],
        ],
        'wednesday' => [
            [
                'question' => "Com que frequência devo revisar {$title}?",
                'answer' => 'Depende do uso e do ambiente, mas revisões rápidas e recorrentes ajudam a detectar sujeira, desgaste e folgas antes de um problema maior.',
            ],
            [
                'question' => 'Quando é melhor parar e pedir ajuda?',
                'answer' => 'Se houver risco estrutural, elétrica, carga elevada, ferrugem avançada ou peça danificada, interrompa a tentativa caseira e procure suporte qualificado.',
            ],
        ],
        default => [
            [
                'question' => "Preciso comprar tudo de uma vez para {$title}?",
                'answer' => 'Nao. O melhor resultado costuma vir da compra do que realmente resolve a necessidade atual, sem excesso de itens pouco usados.',
            ],
            [
                'question' => 'Como encontrar produtos relacionados no catálogo?',
                'answer' => "Use a busca da ShopVivaliz com termos da categoria {$profile['category']} e compare aplicacao, medidas e facilidade de uso antes de decidir.",
            ],
        ],
    };
}

function sv_blog_editorial_keywords(string $title, array $profile): array
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    if (!is_string($normalized) || $normalized === '') {
        $normalized = $title;
    }

    $tokens = preg_split('/[^a-z0-9]+/i', strtolower($normalized)) ?: [];
    $stopWords = ['como', 'para', 'com', 'sem', 'dos', 'das', 'uma', 'mais', 'cada', 'entre', 'guia', 'item', 'itens', 'tipo', 'tipos', 'antes'];
    $keywords = [$profile['category'], $profile['search_term']];

    foreach ($tokens as $token) {
        if ($token === '' || strlen($token) < 4 || in_array($token, $stopWords, true)) {
            continue;
        }
        $keywords[] = $token;
        if (count(array_unique($keywords)) >= 5) {
            break;
        }
    }

    return array_values(array_unique($keywords));
}

function sv_blog_editorial_truncate(string $value, int $limit): string
{
    if (sv_blog_editorial_length($value) <= $limit) {
        return $value;
    }

    return rtrim(sv_blog_editorial_substr($value, 0, $limit - 3)) . '...';
}

function sv_blog_editorial_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (!is_string($normalized) || $normalized === '') {
        $normalized = $value;
    }

    return strtolower($normalized);
}

function sv_blog_editorial_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function sv_blog_editorial_substr(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length, 'UTF-8');
    }

    return substr($value, $start, $length);
}
