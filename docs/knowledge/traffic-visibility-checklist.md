# Checklist de Tráfego e Visibilidade para Site Próprio

Use este documento para auditoria, priorização e acompanhamento das iniciativas de aquisição, SEO, performance, conversão, retenção e dados do ShopVivaliz.

## 1. Estrutura técnica e rastreamento

- [x] Google Tag Manager (GTM): instalação centralizada para gerenciar tags sem reduzir a velocidade do site.
- [x] Google Analytics 4 (GA4): configuração do funil de e-commerce avançado (`view_item` → `add_to_cart` → `begin_checkout` → `purchase`).
- [ ] Enhanced Conversions: ativação no Google Ads com envio seguro de dados criptografados.
- [ ] Meta Conversions API (CAPI): rastreamento server-side para Instagram e Facebook Ads.
- [ ] Server-Side Tagging: migração do GTM Web para GTM Server-Side quando houver infraestrutura disponível.
- [ ] Atribuição baseada em dados: ativação no GA4 e Google Ads.

## 2. Atração e mídia paga

- [x] Google Merchant Center: criação da conta e envio automatizado do feed de produtos.
- [ ] Google Shopping / Performance Max: campanhas em Pesquisa, Shopping, YouTube e Gmail.
- [ ] Otimização para novos clientes na PMax.
- [ ] Google CSS: avaliar parceiro homologado para redução de custo no Shopping.
- [ ] Meta Ads com foco em vídeo curto demonstrando produtos em uso.
- [ ] Remarketing dinâmico para abandono de carrinho e visualização de produtos.
- [ ] Microsoft Advertising: importar campanhas do Google Ads quando aplicável.

## 3. SEO técnico e buscadores orgânicos

- [x] Google Search Console: vincular domínio e enviar sitemap XML.
- [ ] IndexNow: avisar buscadores sobre novos produtos e alterações de preço. Status atual: chave publicada; primeira submissão retornou `SiteVerificationNotCompleted`, retentar após propagação.
- [x] Títulos de produtos no padrão `[Marca + Categoria + Modelo + Atributo]`.
- [x] SEO de imagens: nomes descritivos, formato WebP e `alt` relevante.
- [x] Dados estruturados de produto (`Product`, `Offer`, `AggregateRating`).
- [x] FAQ Schema em categorias e produtos.
- [x] Estrutura semântica e grafo de conhecimento para entidades da loja, marcas, categorias e produtos.

## 4. Experiência, performance e conversão

- [ ] Core Web Vitals: otimizar LCP, CLS e INP.
- [ ] Imagens WebP/AVIF, lazy loading e preload da imagem principal.
- [ ] CDN avançada e edge computing quando houver infraestrutura disponível.
- [ ] Microsoft Clarity ou ferramenta equivalente para mapas de calor e gravação de sessões.
- [x] Busca interna inteligente com tolerância a erros e busca fonética.
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

## Status Atualizado em 2026-07-19

| Item | Status | Evidência / bloqueio |
|---|---:|---|
| GTM | IMPLEMENTADO | Container `GTM-PHZ55CP3` presente no site. |
| GA4 | IMPLEMENTADO | Stream `G-1H55K1TZ5D` acessível e recebendo tráfego. |
| Funil GA4 e-commerce | IMPLEMENTADO | Eventos `view_item`, `add_to_cart`, `begin_checkout`, `purchase` presentes no código. |
| Enhanced Conversions | BLOQUEADO_POR_CREDENCIAL | Código existe, mas faltam `GOOGLE_ADS_ID` e `GOOGLE_ADS_CONVERSION_LABEL` reais. |
| Google Merchant Center | IMPLEMENTADO | Feed dedicado por URL cadastrado; 177 produtos adicionados; arquivo sem problema básico. |
| Google Shopping / PMax | BLOQUEADO_POR_APROVACAO | Não ativar campanha paga sem aprovação de orçamento e conta Ads liberada. |
| Google Ads API | BLOQUEADO_POR_GOOGLE | Developer token em modo `Conta de teste`; marca OAuth em análise. |
| Search Console | IMPLEMENTADO | Domínio verificado e sitemap processado em 2026-07-19. |
| IndexNow | PARCIAL | Chave publicada; submissão retornou `SiteVerificationNotCompleted`; retry diário configurado. |
| SEO Google Shopping | IMPLEMENTADO | Feed com 177 itens, 0 títulos `PRODUTO_...`, 0 boilerplate; auditoria item a item gerada. |
| SEO de imagens | IMPLEMENTADO_PARCIAL | `alt` relevante em cards/produto e preload da imagem principal; nomes físicos de imagens S3 dependem da origem. |
| Schema Product/Offer/Breadcrumb/FAQ | IMPLEMENTADO | Produto, breadcrumb e FAQPage em páginas de produto; CollectionPage, SearchAction e FAQPage no catálogo; FAQPage na home. |
| Core Web Vitals | PARCIAL | Lazy loading, preload/fetchpriority e responsividade/zoom aplicados; Lighthouse/PageSpeed externo ainda não registrado. |
| Busca interna | IMPLEMENTADO_PARCIAL | Busca do catálogo aceita `q`/`busca`, normaliza acentos, usa sinônimos por categoria e tolerância básica a erro de digitação. |
| Checkout transparente | IMPLEMENTADO_PARCIAL | Checkout em página única funcional; estado em select; pagamento Pix/boleto com e-mails corrigidos. |
| Pix automatizado | IMPLEMENTADO | Webhook/e-mail envia QR/copia-e-cola quando Mercado Pago retorna dados Pix. |
| Melhor Envio | IMPLEMENTADO_PARCIAL | Integração em código; UF preservada como `MG`, Mercado Pago recebe `Minas Gerais`. |
| Olist ERP catálogo | IMPLEMENTADO_PARCIAL | Catálogo/feed usam origem canônica; 4 itens dependem correção no cadastro-fonte. |
