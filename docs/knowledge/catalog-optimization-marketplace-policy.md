# Otimizacao de cadastro por marketplace

## Escopo

O fluxo canonico do Admin e `/admin/catalog-optimization/admin_catalog.php` -> `/admin/catalog-optimization/api/optimize_catalog.php`.

Este modulo otimiza apenas conteudo editorial, identificadores e SEO. Preco e estoque sao campos protegidos e nao podem ser lidos para prompt, alterados ou gravados por esta rotina.

## Principios

- Nunca inventar marca, modelo, GTIN/EAN, MPN, material, cor, tamanho, compatibilidade, certificacao, garantia, autenticidade, desempenho ou aplicacao.
- Preservar identificadores reais e variantes.
- Omitir dado ausente em vez de preencher com valor generico.
- Bloquear preco, estoque, desconto, cupom, parcelamento e frete gratis na saida gerada.
- Registrar score e checks de qualidade no staging.
- Conteudo reprovado pela politica do canal deve falhar antes de entrar como `pending`.
- Distinguir limite oficial do marketplace de alvo interno de qualidade; nunca tratar uma preferencia editorial interna como regra oficial.

## Mercado Livre

Priorizar identidade estruturada do produto e atributos da categoria. O limite de titulo e definido pela categoria (`max_title_length`); enquanto esse valor nao estiver disponivel no fluxo local, o engine usa 60 caracteres como teto conservador. Em User Products o titulo muda de funcao e os dados estruturados (marca, modelo, GTIN/MPN, variacao e atributos) passam a ser ainda mais importantes. Condicoes comerciais e a palavra estoque nao entram no titulo.

## Shopee

Priorizar legibilidade mobile, tipo do produto, marca/modelo quando existentes e atributos de decisao. O alvo operacional local e um titulo de ate 120 caracteres, sem preencher texto so para atingir tamanho. Nao usar urgencia ou escassez artificiais, claims de garantia/autenticidade sem fonte, nem marcas de terceiros para capturar trafego.

## Amazon

A politica geral atual permite ate 200 caracteres para a maioria das categorias. O engine prefere 80-150 quando isso preserva a identidade completa do produto, sem transformar essa faixa em limite oficial. Sao bloqueados caracteres promocionais proibidos e repeticao da mesma palavra mais de duas vezes, exceto artigos, preposicoes e conjuncoes. Usar exatamente cinco bullets factuais.

## TikTok Shop

Titulo obrigatoriamente entre 25 e 200 caracteres; o alvo de performance usado pelo engine e 40-150. Deve conter somente dados essenciais de identificacao e descoberta. Quando a origem tiver material factual suficiente, a descricao deve buscar 500+ caracteres sem padding, repeticao ou invencao. Usar 3 a 5 selling points curtos e tres hooks factuais para video/live. Proibidos referencias a estoque/inventario, desconto, medo, falsa urgencia, escassez e promessas nao comprovadas.

## Site proprio

SEO e GEO factuais: resposta direta no inicio, descricao profunda baseada em evidencias, meta title e meta description sem keyword stuffing ou claims inventados.

## Olist / Tiny ERP

Cadastro estritamente tecnico. Sem copy promocional, hooks ou SEO comercial.

## Validacao

O teste `tests/catalog-optimization-policy-test.php` cobre:

- bloqueio de preco/estoque/condicao comercial;
- bloqueio de garantia sem fonte;
- limite de 200 caracteres, caracteres e repeticao de titulo Amazon;
- faixa obrigatoria de 25-200 caracteres e estrutura TikTok Shop;
- ERP sem SEO/hook;
- preservacao de marca real;
- ausencia de preco/estoque no prompt;
- aplicacao do mesmo quality gate no fluxo de regeneracao do Admin.
