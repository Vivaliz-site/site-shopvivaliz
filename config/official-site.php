<?php
declare(strict_types=1);

return [
    'base_url' => 'https://shopvivaliz.com.br',
    // Rodada 6 (2026-08-19): 3 das 4 rotas abaixo (about/help/contact)
    // devolviam 404 em producao -- confirmado via curl. 'terms' era a unica
    // correta. Trocadas pelos destinos finais reais (confirmados via
    // .htaccess: linhas 270-272 fazem rewrite interno de /sobre, /contato,
    // /faq para os respectivos index.php; /sobre e /contato tem diretorio
    // fisico, entao Apache faz 301 pra versao com barra final antes do
    // rewrite -- gravado aqui ja com a barra, o destino final). Nao existe
    // rota "/p/quem-somos", "/p/ajuda" ou "/p/atendimento" no site. Ver R6-5
    // no relatorio da Rodada 6.
    'verified_at' => '2026-08-19',
    'source' => 'official_storefront',
    'navigation' => [
        'terms' => '/termos',
        'about' => '/sobre/',
        'help' => '/faq/',
        'contact' => '/contato/',
    ],
    'payment_methods' => ['Visa', 'Mastercard', 'Boleto', 'Depósito', 'Pix'],
    'top_categories' => [
        'Casa, Móveis e Decoração',
        'Ferramentas',
        'Construção',
        'Acessórios para Veículos',
        'Pet Shop',
        'Arte, Papelaria e Armarinho',
    ],
];
