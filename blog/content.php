<?php
declare(strict_types=1);

/**
 * Base editorial inicial da Central de Conhecimento.
 *
 * Mantida em PHP para funcionar no ambiente legado sem exigir migracao de banco.
 * A estrutura pode ser substituida por MySQL ou CMS sem alterar as paginas publicas.
 */
function sv_blog_articles(): array
{
    return [
        [
            'slug' => 'como-escolher-ferramentas-para-casa',
            'title' => 'Como escolher ferramentas para ter em casa: guia prático',
            'excerpt' => 'Veja quais ferramentas resolvem a maioria dos reparos domésticos e como escolher modelos seguros, duráveis e adequados ao seu uso.',
            'category' => 'Guias de compra',
            'published_at' => '2026-07-25',
            'updated_at' => '2026-07-25',
            'author' => 'Equipe ShopVivaliz',
            'reading_time' => 7,
            'image' => '/public/assets/category-images/cat-ferramentas.jpg',
            'image_alt' => 'Ferramentas essenciais organizadas para pequenos reparos em casa',
            'meta_title' => 'Ferramentas para ter em casa: guia de escolha | ShopVivaliz',
            'meta_description' => 'Aprenda a montar um kit de ferramentas para casa, escolher materiais seguros e evitar compras desnecessárias.',
            'keywords' => ['ferramentas para casa', 'kit de ferramentas', 'guia de compra'],
            'featured' => true,
            'content' => [
                [
                    'heading' => 'Por que vale a pena montar um kit básico?',
                    'paragraphs' => [
                        'Pequenos apertos, ajustes de móveis, instalação de suportes e reparos simples aparecem com frequência. Ter as ferramentas certas evita improvisos, reduz o risco de danos e torna o trabalho mais rápido.',
                        'O melhor kit não é necessariamente o maior. Para uso doméstico, vale priorizar peças versáteis, fáceis de guardar e compatíveis com as tarefas que realmente fazem parte da sua rotina.'
                    ]
                ],
                [
                    'heading' => 'As ferramentas essenciais para começar',
                    'paragraphs' => [
                        'Uma trena ajuda a conferir medidas antes de comprar móveis ou instalar acessórios. Um martelo de tamanho médio atende fixações comuns. Alicate universal, chave ajustável e um jogo de chaves de fenda e Phillips cobrem grande parte dos ajustes domésticos.',
                        'Também é útil manter um nível compacto, estilete com trava, lanterna, fita isolante e uma caixa organizadora. Equipamentos de proteção, como óculos e luvas adequadas, devem acompanhar qualquer atividade que produza partículas, rebarbas ou risco de corte.'
                    ],
                    'list' => [
                        'Trena com trava e graduação legível',
                        'Martelo de tamanho compatível com uso doméstico',
                        'Alicate universal com cabo isolado quando aplicável',
                        'Jogo de chaves Phillips e de fenda',
                        'Chave ajustável',
                        'Nível compacto',
                        'Estilete com trava',
                        'Itens de proteção individual'
                    ]
                ],
                [
                    'heading' => 'Como avaliar qualidade e segurança',
                    'paragraphs' => [
                        'Observe se cabos e empunhaduras estão firmes, sem folgas ou rebarbas. Ferramentas metálicas devem apresentar acabamento uniforme e encaixes precisos. Em peças articuladas, o movimento precisa ser suave, mas sem jogo excessivo.',
                        'Para trabalhos elétricos, use somente ferramentas indicadas para essa finalidade e respeite as especificações do fabricante. Desligar a alimentação elétrica e confirmar a ausência de tensão são etapas indispensáveis; quando houver dúvida, procure um profissional qualificado.'
                    ]
                ],
                [
                    'heading' => 'Manual ou elétrica: qual escolher?',
                    'paragraphs' => [
                        'Ferramentas manuais são econômicas, ocupam pouco espaço e atendem bem tarefas ocasionais. Ferramentas elétricas fazem sentido quando o trabalho exige repetição, força ou maior velocidade.',
                        'Uma furadeira, por exemplo, pode ser útil para instalações frequentes. Antes da compra, confira potência, tipo de mandril, acessórios disponíveis e compatibilidade com a superfície que será perfurada.'
                    ]
                ],
                [
                    'heading' => 'Como conservar seu kit',
                    'paragraphs' => [
                        'Limpe as ferramentas após o uso, guarde-as secas e evite contato prolongado com umidade. Não utilize chaves como alavancas nem alicates como martelos: o uso incorreto reduz a vida útil e pode causar acidentes.',
                        'Organizar as peças por função também facilita perceber perdas, desgaste ou necessidade de substituição antes do próximo reparo.'
                    ]
                ]
            ],
            'faq' => [
                ['question' => 'Qual é a primeira ferramenta que devo comprar?', 'answer' => 'Para um kit doméstico, comece por trena, martelo, alicate universal e um conjunto de chaves. A prioridade depende dos reparos mais comuns na sua casa.'],
                ['question' => 'Preciso comprar um kit com muitas peças?', 'answer' => 'Não. Kits muito grandes podem incluir itens que nunca serão usados. Prefira ferramentas essenciais de boa qualidade e amplie o conjunto conforme surgir necessidade.'],
                ['question' => 'Como guardar ferramentas para evitar ferrugem?', 'answer' => 'Guarde-as limpas e completamente secas, de preferência em caixa fechada e longe de locais úmidos. Siga as orientações de conservação do fabricante.']
            ],
            'related_products_url' => '/catalogo?busca=ferramentas'
        ],
        [
            'slug' => 'como-organizar-casa-com-praticidade',
            'title' => 'Como organizar a casa com praticidade e sem excessos',
            'excerpt' => 'Um método simples para escolher organizadores, aproveitar melhor os espaços e manter cada ambiente funcional.',
            'category' => 'Organização',
            'published_at' => '2026-07-25',
            'updated_at' => '2026-07-25',
            'author' => 'Equipe ShopVivaliz',
            'reading_time' => 5,
            'image' => '/public/assets/category-images/cat-organizacao.jpg',
            'image_alt' => 'Ambiente doméstico organizado com caixas e acessórios funcionais',
            'meta_title' => 'Como organizar a casa com praticidade | ShopVivaliz',
            'meta_description' => 'Descubra como planejar a organização da casa, escolher acessórios úteis e evitar o acúmulo de organizadores.',
            'keywords' => ['organização da casa', 'organizadores', 'casa prática'],
            'featured' => false,
            'content' => [
                ['heading' => 'Comece pelo uso, não pelo produto', 'paragraphs' => ['Antes de comprar caixas, cestos ou divisórias, observe o que precisa ser guardado, com que frequência é usado e onde faz mais sentido ficar.', 'Medir o espaço disponível evita acessórios grandes demais, pequenos demais ou que dificultam o acesso.']],
                ['heading' => 'Agrupe por função', 'paragraphs' => ['Itens usados juntos devem permanecer próximos. Na cozinha, agrupe preparo, armazenamento e limpeza. No banheiro, separe higiene diária de reposições.', 'Essa lógica reduz deslocamentos e torna mais fácil devolver cada objeto ao lugar.']],
                ['heading' => 'Prefira soluções fáceis de manter', 'paragraphs' => ['A organização precisa funcionar na rotina real. Recipientes laváveis, etiquetas legíveis e acesso simples costumam ser mais eficientes do que sistemas complexos.', 'Revise os espaços periodicamente e retire o que perdeu utilidade antes de adicionar novos organizadores.']]
            ],
            'faq' => [
                ['question' => 'Devo comprar organizadores antes de separar os objetos?', 'answer' => 'O ideal é primeiro selecionar, agrupar e medir. Assim você compra apenas o tamanho e o tipo necessários.'],
                ['question' => 'Como manter a organização por mais tempo?', 'answer' => 'Defina locais simples para cada grupo de itens e faça pequenas revisões frequentes, em vez de esperar uma reorganização completa.']
            ],
            'related_products_url' => '/catalogo?busca=organizacao'
        ],
        [
            'slug' => 'cuidados-com-ferragens-em-areas-umidas',
            'title' => 'Ferragens em áreas úmidas: como escolher e conservar',
            'excerpt' => 'Entenda o que observar em ferragens para banheiro, cozinha, lavanderia e áreas externas.',
            'category' => 'Manutenção',
            'published_at' => '2026-07-25',
            'updated_at' => '2026-07-25',
            'author' => 'Equipe ShopVivaliz',
            'reading_time' => 6,
            'image' => '/public/assets/category-images/cat-ferragens.jpg',
            'image_alt' => 'Ferragens e acessórios metálicos indicados para ambientes úmidos',
            'meta_title' => 'Ferragens para áreas úmidas: escolha e conservação',
            'meta_description' => 'Saiba como escolher ferragens para áreas úmidas e quais cuidados ajudam a preservar acabamento e funcionamento.',
            'keywords' => ['ferragens', 'áreas úmidas', 'conservação'],
            'featured' => false,
            'content' => [
                ['heading' => 'Umidade exige atenção ao material', 'paragraphs' => ['Banheiros, cozinhas, lavanderias e áreas externas expõem ferragens à água, vapor e produtos de limpeza. O material e o acabamento precisam ser adequados ao ambiente.', 'Consulte sempre as especificações do fabricante para confirmar indicação de uso interno, externo ou contato frequente com umidade.']],
                ['heading' => 'Instalação influencia a durabilidade', 'paragraphs' => ['Fixação desalinhada, aperto excessivo ou uso de parafusos incompatíveis podem danificar o acabamento e comprometer o funcionamento.', 'Use os componentes recomendados e respeite a capacidade de carga. Para instalações estruturais ou com risco de queda, procure mão de obra qualificada.']],
                ['heading' => 'Limpeza sem agressão', 'paragraphs' => ['Evite abrasivos e produtos químicos não recomendados. Em muitos casos, pano macio, água e detergente neutro são suficientes.', 'Seque a superfície após a limpeza e verifique periodicamente sinais de oxidação, folgas ou desgaste.']]
            ],
            'faq' => [
                ['question' => 'Toda peça de metal pode ser usada no banheiro?', 'answer' => 'Não. A resistência depende do material, acabamento e indicação do fabricante. Confirme a aplicação antes da compra.'],
                ['question' => 'Posso usar palha de aço para limpar ferragens?', 'answer' => 'Em geral, abrasivos podem riscar ou remover o acabamento. Siga a orientação específica do fabricante da peça.']
            ],
            'related_products_url' => '/catalogo?busca=ferragens'
        ]
    ];
}

function sv_blog_find_article(string $slug): ?array
{
    foreach (sv_blog_articles() as $article) {
        if (($article['slug'] ?? '') === $slug) {
            return $article;
        }
    }

    return null;
}

function sv_blog_categories(): array
{
    $categories = [];
    foreach (sv_blog_articles() as $article) {
        $category = (string)($article['category'] ?? 'Conteúdo');
        $categories[$category] = ($categories[$category] ?? 0) + 1;
    }

    ksort($categories);
    return $categories;
}

function sv_blog_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sv_blog_date(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y', $timestamp) : $date;
}
