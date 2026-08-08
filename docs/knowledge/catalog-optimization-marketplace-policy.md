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

## Mercado Livre

Priorizar identidade estruturada do produto e atributos da categoria. Para anuncios legados, manter titulo enxuto e factual, sem condicoes comerciais. Em fluxos User Products, marca, modelo, GTIN/MPN, variacao e atributos sao a base da identidade.

## Shopee

Priorizar legibilidade mobile, tipo do produto, marca/modelo quando existentes e atributos de decisao. Nao usar urgencia ou escassez artificiais, claims de garantia/autenticidade sem fonte, nem marcas de terceiros para capturar trafego.

## Amazon

Titulo mobile-first de ate 75 caracteres para o perfil operacional atual, sem emojis/promocoes e sem repeticao abusiva. Usar exatamente cinco bullets factuais. Item Highlight, quando usado, deve ser factual e curto.

## TikTok Shop

Titulo factual dentro do limite tecnico, descricao detalhada quando houver dados suficientes, 3 a 5 selling points curtos e tres hooks factuais para video/live. Proibidos medo, falsa urgencia, escassez e promessas nao comprovadas.

## Site proprio

SEO e GEO factuais: resposta direta no inicio, descricao profunda baseada em evidencias, meta title e meta description sem keyword stuffing ou claims inventados.

## Olist / Tiny ERP

Cadastro estritamente tecnico. Sem copy promocional, hooks ou SEO comercial.

## Validacao

O teste `tests/catalog-optimization-policy-test.php` cobre:

- bloqueio de preco/estoque/condicao comercial;
- bloqueio de garantia sem fonte;
- limites e repeticao de titulo Amazon;
- estrutura TikTok Shop;
- ERP sem SEO/hook;
- preservacao de marca real;
- ausencia de preco/estoque no prompt.
