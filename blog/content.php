<?php
declare(strict_types=1);

/**
 * Base editorial inicial da Central de Conhecimento.
 *
 * Mantida em PHP para funcionar no ambiente legado sem exigir migracao de banco.
 * A estrutura pode ser substituida por MySQL ou CMS sem alterar as paginas publicas.
 */
function sv_blog_make_article(
    string $slug,
    string $title,
    string $excerpt,
    string $category,
    string $image,
    string $imageAlt,
    string $metaTitle,
    string $metaDescription,
    array $keywords,
    array $sections,
    array $faq,
    string $relatedProductsUrl,
    int $readingTime = 5,
    bool $featured = false
): array {
    return [
        'slug' => $slug,
        'title' => $title,
        'excerpt' => $excerpt,
        'category' => $category,
        'published_at' => '2026-07-25',
        'updated_at' => '2026-07-25',
        'author' => 'Equipe ShopVivaliz',
        'reading_time' => $readingTime,
        'image' => '/public/assets/blog/' . $slug . '.jpg',
        'image_alt' => $imageAlt,
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'keywords' => $keywords,
        'featured' => $featured,
        'content' => $sections,
        'faq' => $faq,
        'related_products_url' => $relatedProductsUrl,
    ];
}

function sv_blog_articles(): array
{
    return [
        sv_blog_make_article(
            'como-escolher-ferramentas-para-casa',
            'Como escolher ferramentas para ter em casa: guia prático',
            'Veja quais ferramentas resolvem a maioria dos reparos domésticos e como escolher modelos seguros, duráveis e adequados ao seu uso.',
            'Guias de compra',
            '/public/assets/category-images/cat-ferramentas.jpg',
            'Ferramentas essenciais organizadas para pequenos reparos em casa',
            'Ferramentas para ter em casa: guia de escolha | ShopVivaliz',
            'Aprenda a montar um kit de ferramentas para casa, escolher materiais seguros e evitar compras desnecessárias.',
            ['ferramentas para casa', 'kit de ferramentas', 'guia de compra'],
            [
                ['heading' => 'Por que vale a pena montar um kit básico?', 'paragraphs' => ['Pequenos apertos, ajustes de móveis, instalação de suportes e reparos simples aparecem com frequência. Ter as ferramentas certas evita improvisos, reduz o risco de danos e torna o trabalho mais rápido.', 'O melhor kit não é necessariamente o maior. Para uso doméstico, vale priorizar peças versáteis, fáceis de guardar e compatíveis com as tarefas que realmente fazem parte da sua rotina.']],
                ['heading' => 'As ferramentas essenciais para começar', 'paragraphs' => ['Uma trena ajuda a conferir medidas antes de comprar móveis ou instalar acessórios. Um martelo de tamanho médio atende fixações comuns. Alicate universal, chave ajustável e um jogo de chaves de fenda e Phillips cobrem grande parte dos ajustes domésticos.', 'Também é útil manter um nível compacto, estilete com trava, lanterna, fita isolante e uma caixa organizadora. Equipamentos de proteção, como óculos e luvas adequadas, devem acompanhar qualquer atividade que produza partículas, rebarbas ou risco de corte.'], 'list' => ['Trena com trava e graduação legível', 'Martelo de tamanho compatível com uso doméstico', 'Alicate universal com cabo isolado quando aplicável', 'Jogo de chaves Phillips e de fenda', 'Chave ajustável', 'Nível compacto', 'Estilete com trava', 'Itens de proteção individual']],
                ['heading' => 'Como avaliar qualidade e segurança', 'paragraphs' => ['Observe se cabos e empunhaduras estão firmes, sem folgas ou rebarbas. Ferramentas metálicas devem apresentar acabamento uniforme e encaixes precisos. Em peças articuladas, o movimento precisa ser suave, mas sem jogo excessivo.', 'Para trabalhos elétricos, use somente ferramentas indicadas para essa finalidade e respeite as especificações do fabricante. Quando houver dúvida, procure um profissional qualificado.']],
            ],
            [
                ['question' => 'Qual é a primeira ferramenta que devo comprar?', 'answer' => 'Para um kit doméstico, comece por trena, martelo, alicate universal e um conjunto de chaves. A prioridade depende dos reparos mais comuns na sua casa.'],
                ['question' => 'Preciso comprar um kit com muitas peças?', 'answer' => 'Não. Kits muito grandes podem incluir itens que nunca serão usados. Prefira ferramentas essenciais de boa qualidade e amplie o conjunto conforme surgir necessidade.'],
            ],
            '/catalogo?busca=ferramentas',
            7,
            true
        ),
        sv_blog_make_article(
            'como-organizar-casa-com-praticidade',
            'Como organizar a casa com praticidade e sem excessos',
            'Um método simples para escolher organizadores, aproveitar melhor os espaços e manter cada ambiente funcional.',
            'Organização',
            '/public/assets/category-images/cat-organizacao.jpg',
            'Ambiente doméstico organizado com caixas e acessórios funcionais',
            'Como organizar a casa com praticidade | ShopVivaliz',
            'Descubra como planejar a organização da casa, escolher acessórios úteis e evitar o acúmulo de organizadores.',
            ['organização da casa', 'organizadores', 'casa prática'],
            [
                ['heading' => 'Comece pelo uso, não pelo produto', 'paragraphs' => ['Antes de comprar caixas, cestos ou divisórias, observe o que precisa ser guardado, com que frequência é usado e onde faz mais sentido ficar.', 'Medir o espaço disponível evita acessórios grandes demais, pequenos demais ou que dificultam o acesso.']],
                ['heading' => 'Agrupe por função', 'paragraphs' => ['Itens usados juntos devem permanecer próximos. Na cozinha, agrupe preparo, armazenamento e limpeza. No banheiro, separe higiene diária de reposições.', 'Essa lógica reduz deslocamentos e torna mais fácil devolver cada objeto ao lugar.']],
                ['heading' => 'Prefira soluções fáceis de manter', 'paragraphs' => ['A organização precisa funcionar na rotina real. Recipientes laváveis, etiquetas legíveis e acesso simples costumam ser mais eficientes do que sistemas complexos.']],
            ],
            [
                ['question' => 'Devo comprar organizadores antes de separar os objetos?', 'answer' => 'O ideal é primeiro selecionar, agrupar e medir. Assim você compra apenas o tamanho e o tipo necessários.'],
                ['question' => 'Como manter a organização por mais tempo?', 'answer' => 'Defina locais simples para cada grupo de itens e faça pequenas revisões frequentes.'],
            ],
            '/catalogo?busca=organizacao',
            5
        ),
        sv_blog_make_article(
            'cuidados-com-ferragens-em-areas-umidas',
            'Ferragens em áreas úmidas: como escolher e conservar',
            'Entenda o que observar em ferragens para banheiro, cozinha, lavanderia e áreas externas.',
            'Manutenção',
            '/public/assets/category-images/cat-ferragens.jpg',
            'Ferragens e acessórios metálicos indicados para ambientes úmidos',
            'Ferragens para áreas úmidas: escolha e conservação',
            'Saiba como escolher ferragens para áreas úmidas e quais cuidados ajudam a preservar acabamento e funcionamento.',
            ['ferragens', 'áreas úmidas', 'conservação'],
            [
                ['heading' => 'Umidade exige atenção ao material', 'paragraphs' => ['Banheiros, cozinhas, lavanderias e áreas externas expõem ferragens à água, vapor e produtos de limpeza. O material e o acabamento precisam ser adequados ao ambiente.', 'Consulte sempre as especificações do fabricante para confirmar indicação de uso interno, externo ou contato frequente com umidade.']],
                ['heading' => 'Instalação influencia a durabilidade', 'paragraphs' => ['Fixação desalinhada, aperto excessivo ou uso de parafusos incompatíveis podem danificar o acabamento e comprometer o funcionamento.', 'Use os componentes recomendados e respeite a capacidade de carga. Para instalações estruturais ou com risco de queda, procure mão de obra qualificada.']],
                ['heading' => 'Limpeza sem agressão', 'paragraphs' => ['Evite abrasivos e produtos químicos não recomendados. Em muitos casos, pano macio, água e detergente neutro são suficientes.', 'Seque a superfície após a limpeza e verifique periodicamente sinais de oxidação, folgas ou desgaste.']],
            ],
            [
                ['question' => 'Toda peça de metal pode ser usada no banheiro?', 'answer' => 'Não. A resistência depende do material, acabamento e indicação do fabricante. Confirme a aplicação antes da compra.'],
                ['question' => 'Posso usar palha de aço para limpar ferragens?', 'answer' => 'Em geral, abrasivos podem riscar ou remover o acabamento. Siga a orientação específica do fabricante da peça.'],
            ],
            '/catalogo?busca=ferragens',
            6
        ),
        sv_blog_make_article(
            'como-escolher-rodizio-ideal',
            'Como escolher o rodízio ideal para móveis, carrinhos e organização',
            'Entenda carga, piso, material da roda e tipo de fixação antes de comprar rodízios.',
            'Guias de compra',
            '/public/assets/category-images/cat-rodizios.jpg',
            'Rodízios para móveis e carrinhos sobre superfície clara',
            'Como escolher rodízio ideal | ShopVivaliz',
            'Guia simples para escolher rodízio conforme carga, piso, material e tipo de fixação.',
            ['rodízio', 'rodízios para móveis', 'rodas para carrinho'],
            [
                ['heading' => 'Comece pela carga', 'paragraphs' => ['Some o peso do móvel ou equipamento com o peso do que será transportado. Depois divida esse valor pela quantidade de rodízios e mantenha uma margem de segurança.', 'Quando a carga é mal calculada, a roda pode travar, desgastar rápido ou danificar o piso.']],
                ['heading' => 'Observe o piso', 'paragraphs' => ['Pisos lisos, ásperos, internos e externos pedem materiais diferentes. Rodas mais macias tendem a proteger melhor pisos delicados; rodas mais rígidas podem ser úteis em superfícies resistentes.', 'Verifique sempre a indicação do fabricante para não usar o rodízio fora da aplicação recomendada.']],
                ['heading' => 'Escolha a fixação correta', 'paragraphs' => ['Existem rodízios com placa, haste, rosca e outras fixações. A escolha depende da estrutura onde a peça será instalada.', 'A instalação precisa ficar firme e alinhada para evitar ruído, instabilidade e desgaste irregular.']],
            ],
            [
                ['question' => 'Posso usar qualquer rodízio em piso laminado?', 'answer' => 'Não. Prefira modelos indicados para pisos delicados e confirme a recomendação do fabricante.'],
                ['question' => 'Rodízio com trava é sempre necessário?', 'answer' => 'É recomendado quando o móvel precisa permanecer parado com segurança, principalmente em áreas de circulação.'],
            ],
            '/catalogo?busca=rodizio',
            6,
            true
        ),
        sv_blog_make_article(
            'guia-de-caixas-organizadoras',
            'Guia de caixas organizadoras: tamanhos, usos e cuidados',
            'Saiba como escolher caixas organizadoras para estoque doméstico, lavanderia, quarto e garagem.',
            'Organização',
            '/public/assets/category-images/cat-organizacao.jpg',
            'Caixas organizadoras empilhadas em ambiente doméstico',
            'Caixas organizadoras: guia de escolha | ShopVivaliz',
            'Aprenda a escolher caixas organizadoras por tamanho, material, tampa, empilhamento e facilidade de limpeza.',
            ['caixas organizadoras', 'organização', 'armazenamento'],
            [
                ['heading' => 'Meça antes de comprar', 'paragraphs' => ['Altura, largura e profundidade do espaço determinam o tamanho útil da caixa. Considere também a abertura de portas, prateleiras e gavetas.', 'Se a caixa será empilhada, avalie estabilidade, tampa e peso dos itens armazenados.']],
                ['heading' => 'Transparente ou fechada?', 'paragraphs' => ['Caixas transparentes facilitam encontrar objetos rapidamente. Caixas opacas podem deixar o ambiente visualmente mais limpo.', 'Para itens de uso frequente, prefira acesso fácil. Para armazenamento de longo prazo, priorize proteção e identificação.']],
                ['heading' => 'Cuidados de uso', 'paragraphs' => ['Evite excesso de peso, exposição direta ao sol quando o material não for indicado e limpeza com produtos agressivos.', 'Etiquetas simples ajudam a manter a organização sem precisar abrir todas as caixas.']],
            ],
            [
                ['question' => 'Caixas organizadoras podem ser empilhadas?', 'answer' => 'Podem, desde que o modelo seja adequado para isso e o peso não comprometa a estabilidade.'],
                ['question' => 'Qual tamanho devo comprar?', 'answer' => 'Depende do espaço disponível e do volume dos objetos. Meça antes e deixe margem para manuseio.'],
            ],
            '/catalogo?busca=caixa%20organizadora',
            5
        ),
        sv_blog_make_article(
            'como-aumentar-vida-util-de-cadeados',
            'Como aumentar a vida útil de cadeados e fechaduras simples',
            'Cuidados de limpeza, uso e armazenamento que ajudam a preservar cadeados por mais tempo.',
            'Manutenção',
            '/public/assets/category-images/cat-ferragens.jpg',
            'Cadeados e ferragens de segurança organizados',
            'Como conservar cadeados | ShopVivaliz',
            'Veja cuidados para conservar cadeados, evitar travamentos e escolher o uso correto para cada ambiente.',
            ['cadeado', 'fechadura', 'segurança doméstica'],
            [
                ['heading' => 'Use no ambiente correto', 'paragraphs' => ['Nem todo cadeado é indicado para área externa, chuva ou maresia. A exposição incorreta pode acelerar oxidação e travamentos.', 'Confira a indicação do fabricante e escolha modelos compatíveis com o local de uso.']],
                ['heading' => 'Evite força excessiva', 'paragraphs' => ['Chaves tortas, batidas e tentativas de abertura forçada podem danificar o mecanismo interno.', 'Quando houver dificuldade, pare o uso e verifique sujeira, desalinhamento ou desgaste antes de insistir.']],
                ['heading' => 'Faça manutenção simples', 'paragraphs' => ['Mantenha o cadeado limpo e seco. Use lubrificantes apenas quando recomendados pelo fabricante.', 'Em locais externos, inspeções periódicas ajudam a identificar desgaste antes de uma falha.']],
            ],
            [
                ['question' => 'Todo cadeado serve para área externa?', 'answer' => 'Não. Verifique se o modelo é indicado para chuva, sol ou umidade frequente.'],
                ['question' => 'Posso lubrificar qualquer cadeado?', 'answer' => 'Siga a recomendação do fabricante. Produto inadequado pode acumular sujeira ou prejudicar o mecanismo.'],
            ],
            '/catalogo?busca=cadeado',
            5
        ),
        sv_blog_make_article(
            'organizacao-inteligente-para-cozinha',
            'Organização inteligente para cozinha pequena',
            'Ideias práticas para ganhar espaço, reduzir bagunça e deixar itens de uso diário mais acessíveis.',
            'Organização',
            '/public/assets/category-images/cat-organizacao.jpg',
            'Cozinha organizada com acessórios e recipientes',
            'Organização para cozinha pequena | ShopVivaliz',
            'Veja como organizar cozinha pequena com acessórios simples, critérios de uso e revisão de espaços.',
            ['cozinha pequena', 'organização de cozinha', 'utilidades domésticas'],
            [
                ['heading' => 'Separe por frequência de uso', 'paragraphs' => ['Itens usados todos os dias devem ficar em locais de fácil alcance. O que é eventual pode ir para prateleiras altas ou caixas identificadas.', 'Essa decisão simples reduz bagunça em bancadas e gavetas.']],
                ['heading' => 'Aproveite o espaço vertical', 'paragraphs' => ['Suportes, ganchos e prateleiras podem liberar área de bancada quando instalados corretamente.', 'Antes de instalar qualquer item, confirme peso suportado, tipo de parede e fixação adequada.']],
                ['heading' => 'Evite excesso de acessórios', 'paragraphs' => ['Organizadores ajudam quando resolvem um problema real. Comprar muitos acessórios antes de revisar os objetos pode criar mais volume.', 'Faça uma triagem, agrupe os itens e só depois escolha a solução.']],
            ],
            [
                ['question' => 'Como começar a organizar uma cozinha pequena?', 'answer' => 'Retire excessos, agrupe por função e deixe os itens diários nos pontos mais acessíveis.'],
                ['question' => 'Vale usar ganchos e suportes?', 'answer' => 'Sim, quando a instalação é segura e o peso fica dentro da capacidade indicada.'],
            ],
            '/catalogo?busca=cozinha',
            5
        ),
        sv_blog_make_article(
            'como-evitar-ferrugem-em-areas-externas',
            'Como evitar ferrugem em áreas externas',
            'Boas práticas para preservar peças metálicas expostas a sol, chuva e umidade.',
            'Manutenção',
            '/public/assets/category-images/cat-ferragens.jpg',
            'Peças metálicas de uso externo com acabamento protegido',
            'Como evitar ferrugem em áreas externas | ShopVivaliz',
            'Aprenda cuidados de escolha, instalação, limpeza e inspeção para reduzir risco de ferrugem em áreas externas.',
            ['ferrugem', 'área externa', 'manutenção de metal'],
            [
                ['heading' => 'Escolha materiais adequados', 'paragraphs' => ['Áreas externas exigem materiais e acabamentos compatíveis com chuva, sol e variação de temperatura.', 'Quando houver maresia ou exposição intensa, a escolha do produto deve ser ainda mais cuidadosa.']],
                ['heading' => 'Evite acúmulo de água', 'paragraphs' => ['Instalação com pontos de acúmulo de água acelera desgaste. Sempre que possível, mantenha escoamento e ventilação.', 'Secar ou limpar periodicamente ajuda a preservar o acabamento.']],
                ['heading' => 'Inspecione antes de falhar', 'paragraphs' => ['Pequenos pontos de oxidação, folgas e riscos profundos devem ser avaliados cedo.', 'Substituir ou corrigir uma peça no início costuma ser mais simples do que esperar o problema comprometer o conjunto.']],
            ],
            [
                ['question' => 'Ferrugem sempre significa troca imediata?', 'answer' => 'Não necessariamente, mas sinais de oxidação devem ser avaliados. Se houver perda estrutural, substitua a peça.'],
                ['question' => 'Produto de limpeza forte remove ferrugem?', 'answer' => 'Pode danificar o acabamento. Use apenas produtos recomendados para o material.'],
            ],
            '/catalogo?busca=ferragem',
            6
        ),
        sv_blog_make_article(
            'guia-de-manutencao-de-ferramentas',
            'Guia de manutenção de ferramentas: limpeza, armazenamento e uso correto',
            'Rotina simples para conservar ferramentas manuais e reduzir desgaste prematuro.',
            'Manutenção',
            '/public/assets/category-images/cat-ferramentas.jpg',
            'Ferramentas manuais limpas e organizadas em bancada',
            'Manutenção de ferramentas: guia prático | ShopVivaliz',
            'Aprenda como limpar, guardar e usar ferramentas de forma correta para aumentar sua durabilidade.',
            ['manutenção de ferramentas', 'ferramentas manuais', 'conservação'],
            [
                ['heading' => 'Limpe logo após o uso', 'paragraphs' => ['Resíduos de poeira, massa, tinta ou umidade podem prejudicar acabamento e articulações.', 'Use pano seco ou limpeza compatível com o material, sem produtos agressivos não recomendados.']],
                ['heading' => 'Guarde com organização', 'paragraphs' => ['Caixas, suportes e divisórias evitam contato desnecessário entre peças e facilitam encontrar o item certo.', 'Guardar ferramentas molhadas ou em locais úmidos aumenta risco de oxidação.']],
                ['heading' => 'Use cada ferramenta para sua função', 'paragraphs' => ['Chaves não devem substituir alavancas, alicates não devem funcionar como martelos e lâminas cegas aumentam risco de acidente.', 'O uso correto preserva a peça e melhora a segurança.']],
            ],
            [
                ['question' => 'Posso lavar ferramentas com água?', 'answer' => 'Depende da ferramenta e do material. Quando usar água, seque completamente antes de guardar.'],
                ['question' => 'Como evitar perda de peças pequenas?', 'answer' => 'Use organizadores com divisórias e mantenha cada grupo de acessórios em local definido.'],
            ],
            '/catalogo?busca=ferramentas',
            5
        ),
        sv_blog_make_article(
            'comparativo-tipos-de-fixadores',
            'Comparativo: tipos de fixadores e quando usar cada um',
            'Parafusos, buchas, ganchos e suportes têm aplicações diferentes. Veja como comparar antes da instalação.',
            'Comparativos',
            '/public/assets/category-images/cat-ferragens.jpg',
            'Fixadores variados organizados por tipo e aplicação',
            'Tipos de fixadores: comparativo | ShopVivaliz',
            'Entenda diferenças entre parafusos, buchas, ganchos e suportes para escolher melhor conforme superfície e carga.',
            ['fixadores', 'parafusos', 'buchas', 'suportes'],
            [
                ['heading' => 'A superfície manda na escolha', 'paragraphs' => ['Alvenaria, madeira, drywall, metal e concreto exigem fixadores diferentes. Usar a peça errada pode causar queda, folga ou dano à superfície.', 'Identifique primeiro onde será instalado e qual carga será suportada.']],
                ['heading' => 'Carga não é detalhe', 'paragraphs' => ['Peso do objeto, vibração, movimentação e frequência de uso influenciam a escolha.', 'Em aplicações estruturais, suspensas ou com risco, procure profissional qualificado.']],
                ['heading' => 'Compare o conjunto completo', 'paragraphs' => ['Parafuso, bucha, arruela, gancho e suporte precisam funcionar juntos.', 'Não adianta uma peça resistente se o restante do conjunto não for compatível.']],
            ],
            [
                ['question' => 'Posso usar a mesma bucha em qualquer parede?', 'answer' => 'Não. A bucha precisa ser compatível com o tipo de parede e a carga.'],
                ['question' => 'Como saber a capacidade de carga?', 'answer' => 'Consulte a especificação do fabricante e considere margem de segurança.'],
            ],
            '/catalogo?busca=fixacao',
            6,
            true
        ),
        sv_blog_make_article(
            'erros-comuns-na-escolha-de-acessorios-para-casa',
            'Erros comuns na escolha de acessórios para casa',
            'Evite compras por impulso, medidas erradas e produtos incompatíveis com o ambiente.',
            'Guias de compra',
            '/public/assets/category-images/cat-organizacao.jpg',
            'Acessórios domésticos organizados para comparação antes da compra',
            'Erros ao escolher acessórios para casa | ShopVivaliz',
            'Conheça erros comuns na compra de acessórios para casa e veja como escolher com mais segurança.',
            ['acessórios para casa', 'guia de compra', 'utilidades domésticas'],
            [
                ['heading' => 'Comprar sem medir', 'paragraphs' => ['Medidas erradas geram devoluções, adaptações ruins e perda de tempo.', 'Antes de comprar, confira largura, altura, profundidade e espaço necessário para abertura ou circulação.']],
                ['heading' => 'Ignorar o material', 'paragraphs' => ['O mesmo acessório pode funcionar bem em um ambiente seco e mal em local úmido ou externo.', 'Material, acabamento e indicação de uso precisam combinar com o ambiente.']],
                ['heading' => 'Escolher só pelo preço', 'paragraphs' => ['Preço é importante, mas deve ser comparado com durabilidade, segurança, facilidade de limpeza e compatibilidade.', 'A melhor compra é a que resolve o problema sem criar outro.']],
            ],
            [
                ['question' => 'Como evitar comprar o acessório errado?', 'answer' => 'Defina o uso, meça o espaço e confira material, instalação e capacidade indicada.'],
                ['question' => 'Vale trocar por um produto mais resistente?', 'answer' => 'Vale quando o uso é frequente, o ambiente é exigente ou a segurança depende da peça.'],
            ],
            '/catalogo?busca=utilidades',
            5
        ),
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

function sv_blog_normalize(string $text): string
{
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $text = strtr($text, $map);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $text));
}

function sv_blog_search_articles(string $query = '', string $category = '', int $limit = 20): array
{
    $queryNorm = sv_blog_normalize($query);
    $categoryNorm = sv_blog_normalize($category);
    $results = [];

    foreach (sv_blog_articles() as $article) {
        $articleCategoryNorm = sv_blog_normalize((string)($article['category'] ?? ''));
        if ($categoryNorm !== '' && $articleCategoryNorm !== $categoryNorm) {
            continue;
        }

        $haystack = sv_blog_normalize(implode(' ', [
            (string)$article['title'],
            (string)$article['excerpt'],
            (string)$article['category'],
            implode(' ', $article['keywords'] ?? []),
        ]));

        $score = 0;
        if ($queryNorm === '') {
            $score = 1;
        } elseif (str_contains($haystack, $queryNorm)) {
            $score = 10;
        } else {
            foreach (preg_split('/\s+/', $queryNorm) ?: [] as $term) {
                if (strlen($term) >= 3 && str_contains($haystack, $term)) {
                    $score += 2;
                }
            }
        }

        if ($score > 0) {
            $article['_score'] = $score;
            $results[] = $article;
        }
    }

    usort($results, static function (array $a, array $b): int {
        $score = ((int)($b['_score'] ?? 0)) <=> ((int)($a['_score'] ?? 0));
        if ($score !== 0) return $score;
        return strcmp((string)($b['published_at'] ?? ''), (string)($a['published_at'] ?? ''));
    });

    return array_slice($results, 0, max(1, $limit));
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
