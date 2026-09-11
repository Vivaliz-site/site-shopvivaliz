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
    $intent = sv_blog_editorial_intent($title);
    $slug = sv_blog_editorial_topic_slug($title);
    $excerpt = sv_blog_editorial_excerpt_for_intent($title, $profile, $intent);
    $metaTitle = sv_blog_editorial_truncate($title . ' | ShopVivaliz', 60);
    $metaDescription = sv_blog_editorial_truncate(
        $excerpt . ' Confira critérios práticos e cuidados antes de decidir.',
        155
    );

    return [
        'slug' => $slug,
        'title' => $title,
        'excerpt' => $excerpt,
        'category' => $profile['category'],
        'image_url' => '/public/assets/blog/' . $slug . '.jpg',
        'image_alt' => $title,
        'content' => sv_blog_editorial_sections_for_intent($title, $profile, $intent),
        'faq' => sv_blog_editorial_faq_for_intent($title, $profile, $intent),
        'keywords' => sv_blog_editorial_keywords($title, $profile),
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'related_products_url' => '/catalogo/?q=' . rawurlencode($profile['search_term']),
        'author' => 'Equipe ShopVivaliz',
        'featured' => false,
        'reading_time' => in_array($intent, ['comparison', 'buying_guide'], true) ? 7 : 6,
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
    } else {
        $headings = [];
        $paragraphs = [];
        $body = '';
        foreach ($content as $section) {
            $heading = trim((string)($section['heading'] ?? ''));
            if ($heading === '') {
                $errors[] = 'empty_content_heading';
            } else {
                $headings[] = sv_blog_editorial_lower($heading);
            }
            foreach (($section['paragraphs'] ?? []) as $paragraph) {
                $normalizedParagraph = trim((string)$paragraph);
                if ($normalizedParagraph !== '') {
                    $paragraphs[] = sv_blog_editorial_lower($normalizedParagraph);
                    $body .= ' ' . $normalizedParagraph;
                }
            }
            foreach (($section['list'] ?? []) as $item) {
                $body .= ' ' . trim((string)$item);
            }
        }
        if (count($headings) !== count(array_unique($headings))) {
            $errors[] = 'duplicate_content_headings';
        }
        if (count($paragraphs) !== count(array_unique($paragraphs))) {
            $errors[] = 'duplicate_content_paragraphs';
        }
        if (sv_blog_editorial_length(trim($body)) < 650) {
            $errors[] = 'content_too_short';
        }
    }

    $legacyPatterns = [
        'Entenda o que avaliar em',
        'Aprenda um passo a passo simples para',
        'Veja ideias objetivas para',
        'O que observar antes de decidir',
        'Onde esse tipo de solução ajuda',
        'Como escolher com equilíbrio',
    ];
    $qualityText = (string)($article['excerpt'] ?? '') . ' ' . implode(' ', is_array($content) ? array_map(
        static fn(array $section): string => (string)($section['heading'] ?? ''),
        $content
    ) : []);
    foreach ($legacyPatterns as $pattern) {
        if (str_contains($qualityText, $pattern)) {
            $errors[] = 'legacy_boilerplate';
            break;
        }
    }

    $metaTitleLength = sv_blog_editorial_length((string)($article['meta_title'] ?? ''));
    $metaDescriptionLength = sv_blog_editorial_length((string)($article['meta_description'] ?? ''));
    if ($metaTitleLength > 60) $errors[] = 'meta_title_too_long';
    if ($metaDescriptionLength < 110) $errors[] = 'meta_description_too_short';
    if ($metaDescriptionLength > 155) $errors[] = 'meta_description_too_long';

    $faq = $article['faq'] ?? [];
    if (!is_array($faq) || count($faq) < 2) {
        $errors[] = 'invalid_faq';
    } else {
        foreach ($faq as $entry) {
            if (sv_blog_editorial_length(trim((string)($entry['question'] ?? ''))) < 20 || trim((string)($entry['answer'] ?? '')) === '') {
                $errors[] = 'faq_not_informative';
                break;
            }
        }
    }

    if (!str_starts_with((string)($article['related_products_url'] ?? ''), '/catalogo/?q=')) {
        $errors[] = 'invalid_related_products_url';
    }

    return array_values(array_unique($errors));
}

function sv_blog_editorial_intent(string $title): string
{
    $haystack = sv_blog_editorial_lower($title);

    if (preg_match('/comparativo|com trava ou sem trava|entre .+ e /u', $haystack) === 1) {
        return 'comparison';
    }
    if (preg_match('/como escolher|guia de compra|guia rápido/u', $haystack) === 1) {
        return 'buying_guide';
    }
    if (preg_match('/limpar|conservar|durabilidade|ferrugem|revisão|revisao|trocar|desgast/u', $haystack) === 1) {
        return 'maintenance';
    }
    if (preg_match('/como guardar|como evitar|como medir|erros comuns|como reduzir|como montar/u', $haystack) === 1) {
        return 'tutorial';
    }

    return 'project';
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

function sv_blog_editorial_excerpt_for_intent(string $title, array $profile, string $intent): string
{
    return match ($intent) {
        'comparison' => "Compare {$title} pelos critérios que realmente mudam o uso: aplicação, instalação, ambiente e manutenção.",
        'buying_guide' => "Use este guia para decidir {$title} com critérios de medida, compatibilidade, segurança e frequência de uso.",
        'maintenance' => "Veja como cuidar de {$title}, reconhecer sinais de desgaste e saber quando a manutenção simples deixa de ser suficiente.",
        'tutorial' => "Siga uma sequência prática para {$title}, com preparação, execução, conferência do resultado e erros que vale evitar.",
        default => "Planeje {$title} a partir do espaço disponível, da rotina e do que realmente precisa ficar acessível no dia a dia.",
    };
}

function sv_blog_editorial_sections_for_intent(string $title, array $profile, string $intent): array
{
    $category = (string)$profile['category'];

    return match ($intent) {
        'comparison' => [
            [
                'heading' => 'Diferenças que mudam a escolha',
                'paragraphs' => [
                    "Em {$title}, a comparação útil começa pela aplicação. Observe onde a peça será usada, quanto esforço recebe, como será instalada e se ficará exposta a umidade, poeira ou circulação intensa.",
                    "Dentro de {$category}, duas opções visualmente parecidas podem se comportar de forma diferente. Material, formato de fixação, manutenção e limite informado pelo fabricante pesam mais do que uma diferença pequena de aparência.",
                ],
            ],
            [
                'heading' => 'Qual opção faz sentido em cada cenário',
                'paragraphs' => [
                    'Escolha a alternativa mais simples quando ela atender completamente ao uso previsto. Recursos extras só valem a pena quando resolvem uma necessidade concreta, como imobilização, exposição externa, limpeza frequente ou manuseio diário.',
                    'Se houver carga, fixação estrutural ou risco de queda, confirme a especificação do fabricante e a capacidade do conjunto completo, não apenas de uma peça isolada.',
                ],
                'list' => [
                    'defina primeiro o ambiente e a frequência de uso',
                    'compare medidas e forma de instalação',
                    'considere limpeza, desgaste e reposição',
                    'confirme limites técnicos antes de improvisar adaptações',
                ],
            ],
            [
                'heading' => 'Checklist antes de comprar',
                'paragraphs' => [
                    'Anote medidas, tire uma foto do ponto de instalação e confira o material onde a peça será fixada. Esses três dados evitam boa parte das compras incompatíveis.',
                    'No catálogo, use o termo relacionado como ponto de partida e abra a página de cada produto para conferir dimensões, indicação de uso e demais características oficiais antes da decisão.',
                ],
            ],
        ],
        'buying_guide' => [
            [
                'heading' => 'Defina a aplicação primeiro',
                'paragraphs' => [
                    "Para {$title}, comece descrevendo o problema em uma frase: o que precisa ser apoiado, protegido, movimentado, organizado ou fixado e em qual ambiente. Isso elimina opções que não servem ao uso real.",
                    "Meça o espaço e o ponto de instalação antes de comparar produtos de {$category}. Quando existir peça antiga, leve também as medidas e o tipo de encaixe para não depender apenas da semelhança visual.",
                ],
            ],
            [
                'heading' => 'Critérios para comparar',
                'paragraphs' => [
                    'Priorize compatibilidade, material, instalação e manutenção. Depois compare ergonomia, acabamento e conveniência. Preço só faz sentido depois que as alternativas incompatíveis foram descartadas.',
                    'Em aplicações com carga, eletricidade, corte ou fixação crítica, respeite a indicação do fabricante e procure instalação qualificada quando a segurança depender da montagem correta.',
                ],
                'list' => [
                    'medidas e compatibilidade com o local',
                    'material adequado ao ambiente',
                    'forma de instalação e ferramentas necessárias',
                    'frequência de uso e facilidade de manutenção',
                ],
            ],
            [
                'heading' => 'Sinais de uma escolha inadequada',
                'paragraphs' => [
                    'Folga, esforço excessivo, necessidade de adaptação improvisada ou dificuldade para manter a peça estável são sinais de que vale interromper a instalação e rever o modelo escolhido.',
                    'Antes de concluir a compra, compare a descrição oficial do produto com suas anotações. Se uma característica importante não estiver informada, não presuma compatibilidade.',
                ],
            ],
        ],
        'maintenance' => [
            [
                'heading' => 'O que causa desgaste',
                'paragraphs' => [
                    "No tema {$title}, desgaste costuma acelerar quando há umidade, poeira, sobrecarga, atrito fora do normal ou armazenamento inadequado. Identificar a causa evita tratar só o sintoma.",
                    "Peças de {$category} também podem perder desempenho por fixação frouxa, limpeza agressiva ou uso fora da aplicação indicada. Observe mudanças de ruído, movimento, acabamento e resistência durante o uso.",
                ],
            ],
            [
                'heading' => 'Rotina de cuidado',
                'paragraphs' => [
                    'Comece com inspeção visual e limpeza compatível com o material. Aperte somente fixações que deveriam estar firmes e não aplique lubrificante ou produto químico sem orientação do fabricante.',
                    'Depois da manutenção, teste em condição leve antes de retornar ao uso normal. Se o problema reaparecer rapidamente, investigue desalinhamento, carga, ambiente ou peça incompatível.',
                ],
                'list' => [
                    'remova sujeira sem abrasivos desnecessários',
                    'observe folgas, trincas, oxidação e deformações',
                    'confira a fixação sem forçar roscas ou encaixes',
                    'registre recorrências para decidir entre manutenção e troca',
                ],
            ],
            [
                'heading' => 'Quando substituir ou chamar um profissional',
                'paragraphs' => [
                    'Interrompa o uso se houver quebra, deformação, ferrugem profunda, aquecimento, falha de trava ou perda de capacidade de sustentar a aplicação. Manutenção cosmética não corrige dano estrutural.',
                    'Quando a desmontagem envolver eletricidade, carga elevada ou fixação de segurança, a avaliação de um profissional é mais segura do que insistir em uma correção improvisada.',
                ],
            ],
        ],
        'tutorial' => [
            [
                'heading' => 'Prepare antes de começar',
                'paragraphs' => [
                    "Para {$title}, reúna os itens envolvidos, limpe a área e confira medidas, encaixes e pontos de fixação. Preparação simples evita parar no meio do trabalho por falta de espaço ou ferramenta.",
                    "Se o procedimento envolver produtos de {$category}, leia a orientação do fabricante antes de desmontar, apertar ou aplicar qualquer produto de limpeza.",
                ],
            ],
            [
                'heading' => 'Faça nesta ordem',
                'paragraphs' => [
                    'Primeiro identifique a causa ou a necessidade principal. Em seguida faça a menor intervenção capaz de resolver o problema e teste o resultado. Só avance para desmontagem ou substituição se o passo anterior não for suficiente.',
                    'Trabalhar em sequência ajuda a descobrir o que realmente produziu a melhora e reduz o risco de criar novas folgas, misturar peças ou perder a referência de montagem.',
                ],
                'list' => [
                    'registre como estava antes da intervenção',
                    'execute uma mudança por vez',
                    'teste em condição segura e controlada',
                    'pare se surgir resistência, aquecimento ou instabilidade inesperada',
                ],
            ],
            [
                'heading' => 'Erros que comprometem o resultado',
                'paragraphs' => [
                    'Forçar encaixes, escolher ferramenta de tamanho incorreto, ignorar o material da superfície e pular a conferência final são erros frequentes. Eles podem transformar um ajuste simples em dano permanente.',
                    'Se a tarefa exigir conhecimento elétrico, estrutural ou de carga que você não consegue verificar, não improvise. Use suporte qualificado e retome somente com a aplicação segura.',
                ],
            ],
        ],
        default => [
            [
                'heading' => 'Mapeie o espaço e a rotina',
                'paragraphs' => [
                    "Em {$title}, observe primeiro onde os itens ficam hoje, quais são usados todos os dias e o que costuma gerar perda de tempo. A organização deve reduzir movimentos e facilitar a devolução de cada coisa ao lugar.",
                    "Meça prateleiras, nichos, bancadas e circulação antes de escolher acessórios de {$category}. Deixe margem para abrir tampas, retirar caixas e limpar o ambiente sem desmontar o sistema.",
                ],
            ],
            [
                'heading' => 'Escolha só o que resolve o problema',
                'paragraphs' => [
                    'Agrupe por função e frequência. Itens de uso diário ficam acessíveis; reposições e objetos sazonais podem ocupar áreas mais altas ou profundas. Isso reduz a necessidade de comprar organizadores apenas para preencher espaço.',
                    'Prefira soluções laváveis, simples de identificar e proporcionais ao volume real. Se um acessório cria uma etapa extra toda vez que algo é usado, provavelmente não será mantido na rotina.',
                ],
                'list' => [
                    'separe o que precisa ficar à mão',
                    'meça antes de escolher caixas ou suportes',
                    'evite duplicar recipientes para a mesma função',
                    'deixe espaço para manutenção e limpeza',
                ],
            ],
            [
                'heading' => 'Monte um sistema fácil de manter',
                'paragraphs' => [
                    'Teste a organização por alguns dias antes de expandir. Ajustes pequenos revelam se o local escolhido realmente acompanha a rotina da casa e evitam compras por impulso.',
                    'Quando precisar complementar, procure no catálogo pelo tipo de solução e confirme na página do produto as medidas e características oficiais. O objetivo é encaixar o produto no sistema, não reorganizar tudo por causa dele.',
                ],
            ],
        ],
    };
}

function sv_blog_editorial_faq_for_intent(string $title, array $profile, string $intent): array
{
    return match ($intent) {
        'comparison' => [
            ['question' => 'Qual critério devo comparar primeiro entre as opções?', 'answer' => 'Comece pela aplicação e pela compatibilidade com o local. Depois compare instalação, material, manutenção e recursos adicionais.'],
            ['question' => 'Quando um recurso extra realmente vale a pena?', 'answer' => 'Quando ele resolve uma necessidade verificável do uso, como trava, exposição externa, limpeza frequente ou maior frequência de manuseio.'],
        ],
        'buying_guide' => [
            ['question' => 'Quais medidas devo levar antes de escolher o produto?', 'answer' => 'Meça o espaço disponível, o ponto de fixação ou encaixe e, se existir, a peça que será substituída. Confira a ficha oficial do produto antes da compra.'],
            ['question' => 'Como evitar comprar um modelo aparentemente compatível, mas errado?', 'answer' => "Compare aplicação, medidas, material e instalação. Em {$profile['category']}, não presuma compatibilidade apenas pela aparência."],
        ],
        'maintenance' => [
            ['question' => 'Como saber se manutenção simples ainda é suficiente?', 'answer' => 'Se limpeza e conferência de fixação devolvem o funcionamento normal sem folga, deformação ou dano, a manutenção pode bastar. Problemas recorrentes pedem nova avaliação.'],
            ['question' => 'Quais sinais indicam que é melhor substituir a peça?', 'answer' => 'Trincas, deformação, ferrugem profunda, falha de trava, aquecimento ou perda de capacidade são sinais para interromper o uso e avaliar a substituição.'],
        ],
        'tutorial' => [
            ['question' => 'Qual é a melhor forma de evitar retrabalho durante o processo?', 'answer' => 'Registre o estado inicial, faça uma mudança por vez e teste antes de avançar. Assim fica claro qual etapa resolveu ou agravou o problema.'],
            ['question' => 'Quando devo interromper o procedimento e procurar ajuda?', 'answer' => 'Pare diante de resistência inesperada, dano, instabilidade, risco elétrico, carga elevada ou qualquer etapa cuja segurança você não consiga verificar.'],
        ],
        default => [
            ['question' => 'Como saber se um organizador ou acessório realmente será útil?', 'answer' => 'Ele deve resolver um problema recorrente, caber com folga no espaço medido e facilitar o acesso ou a devolução dos itens ao lugar.'],
            ['question' => 'Vale reorganizar tudo de uma vez ou testar primeiro?', 'answer' => 'Teste uma área pequena por alguns dias. Ajuste o sistema antes de comprar novos acessórios para evitar excesso e retrabalho.'],
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
