# Checklist de Tráfego e Visibilidade para Site Próprio

Use este documento para auditoria, priorização e acompanhamento das iniciativas de aquisição, SEO, performance, conversão, retenção e dados do ShopVivaliz.

## 1. Estrutura técnica e rastreamento

- [ ] Google Tag Manager (GTM): instalação centralizada para gerenciar tags sem reduzir a velocidade do site.
- [ ] Google Analytics 4 (GA4): configuração do funil de e-commerce avançado (`view_item` → `add_to_cart` → `begin_checkout` → `purchase`).
- [ ] Enhanced Conversions: ativação no Google Ads com envio seguro de dados criptografados.
- [ ] Meta Conversions API (CAPI): rastreamento server-side para Instagram e Facebook Ads.
- [ ] Server-Side Tagging: migração do GTM Web para GTM Server-Side quando houver infraestrutura disponível.
- [ ] Atribuição baseada em dados: ativação no GA4 e Google Ads.

## 2. Atração e mídia paga

- [ ] Google Merchant Center: criação da conta e envio automatizado do feed de produtos.
- [ ] Google Shopping / Performance Max: campanhas em Pesquisa, Shopping, YouTube e Gmail.
- [ ] Otimização para novos clientes na PMax.
- [ ] Google CSS: avaliar parceiro homologado para redução de custo no Shopping.
- [ ] Meta Ads com foco em vídeo curto demonstrando produtos em uso.
- [ ] Remarketing dinâmico para abandono de carrinho e visualização de produtos.
- [ ] Microsoft Advertising: importar campanhas do Google Ads quando aplicável.

## 3. SEO técnico e buscadores orgânicos

- [ ] Google Search Console: vincular domínio e enviar sitemap XML.
- [ ] IndexNow: avisar buscadores sobre novos produtos e alterações de preço.
- [ ] Títulos de produtos no padrão `[Marca + Categoria + Modelo + Atributo]`.
- [ ] SEO de imagens: nomes descritivos, formato WebP e `alt` relevante.
- [ ] Dados estruturados de produto (`Product`, `Offer`, `AggregateRating`).
- [ ] FAQ Schema em categorias e produtos.
- [ ] Estrutura semântica e grafo de conhecimento para entidades da loja, marcas, categorias e produtos.

## 4. Experiência, performance e conversão

- [ ] Core Web Vitals: otimizar LCP, CLS e INP.
- [ ] Imagens WebP/AVIF, lazy loading e preload da imagem principal.
- [ ] CDN avançada e edge computing quando houver infraestrutura disponível.
- [ ] Microsoft Clarity ou ferramenta equivalente para mapas de calor e gravação de sessões.
- [ ] Busca interna inteligente com tolerância a erros e busca fonética.
- [ ] Checkout transparente em página única.
- [ ] Pix automatizado com desconto e destaque visual.

## 5. Logística, ecossistema e retenção

- [ ] Integração com gateways de frete, incluindo Melhor Envio.
- [ ] Integração Olist ERP para estoque e catálogo multicanal.
- [ ] Estratégia inbound em pedidos de marketplace com QR Code e cupom para recompra no site.
- [ ] Prova social automatizada após pedido entregue.
- [ ] Recuperação de carrinho abandonado via WhatsApp e e-mail.

## 6. Infraestrutura avançada de dados

- [ ] CDP para centralizar comportamento, ERP e mídia.
- [ ] Otimização de lances por lucro real usando dados do Olist ERP e Google Ads.
- [ ] Data Clean Room para cruzamento de bases com privacidade e conformidade LGPD.
- [ ] Otimização de feed baseada em clima e contexto local.

## Classificação recomendada

Para cada item, registrar um dos estados:

- `IMPLEMENTADO`
- `PARCIAL`
- `PENDENTE`
- `BLOQUEADO_POR_AUTENTICACAO`
- `BLOQUEADO_POR_CUSTO`
- `NAO_APLICAVEL`

## Prioridade atual do ShopVivaliz

Priorizar primeiro iniciativas sem custo e sem dependência de autenticação externa:

1. Sitemap, robots.txt e canonicals.
2. Schema.org para produto, organização, breadcrumb e FAQ.
3. Open Graph e Twitter Cards.
4. ALT automático e otimização de imagens.
5. Feed de produtos para Google Merchant Center.
6. Estrutura de eventos GA4/GTM preparada por variáveis de ambiente.
7. IndexNow.
8. Core Web Vitals.
9. Busca interna e melhorias de conversão.
10. Monitoramento e health checks.

## Regra operacional

Agentes devem implementar automaticamente todos os itens gratuitos e tecnicamente seguros. Quando houver dependência de login, verificação de domínio, consentimento, faturamento ou alteração em conta externa, marcar como `BLOQUEADO_POR_AUTENTICACAO` e solicitar intervenção humana apenas nesse ponto.