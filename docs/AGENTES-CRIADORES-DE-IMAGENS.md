# Agentes criadores de imagens - ShopVivaliz

## Objetivo

Criar um fluxo produtivo para imagens de produto, banners, anuncios e artes temporarias sem depender de planilhas como fonte principal.

## Agentes

### shopvivaliz-image-creative-director
Define direcao visual, prompts, formatos e canal de uso.

### shopvivaliz-image-bulk-factory
Gera prompts em lote e controla status por produto/template.

### shopvivaliz-image-qa
Valida qualidade, fidelidade ao produto, duplicidade, proporcao e aprovacao.

## Modelos iniciais

- Fundo branco profissional
- Studio premium
- Ambientada realista
- Hero shot ecommerce
- Close-up tecnico
- Banner Google Ads
- Oferta Instagram

## Regras

- Priorizar imagens importadas da Olist/API.
- Nao usar planilha como fonte principal quando a API tiver imagens.
- Nunca alterar caracteristicas essenciais do produto.
- Evitar textos gerados dentro da imagem, salvo banners controlados.
- Cada imagem deve ter status: pending, generated, approved, rejected ou failed.
- Cada falha deve registrar erro e permitir retomada.

## Tabelas relacionadas

- ai_image_templates
- ai_image_jobs
- ai_image_job_items
- generated_media
- olist_product_images

## Proxima etapa

Incluir no pacote cumulativo o painel de fila, templates adicionais e runner automatico de prompts/imagens.
