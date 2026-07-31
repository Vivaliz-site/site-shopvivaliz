# Checklist de Tráfego e Visibilidade Para Site Próprio (E-commerce)

Documento-base para priorizar implementação de rastreamento, aquisição de tráfego, SEO, conversão, retenção e infraestrutura de dados da ShopVivaliz.

## 1. Estrutura Técnica e Configurações de Rastreamento

- [x] Google Tag Manager (GTM): instalação centralizada para gerenciar tags sem reduzir a velocidade do site.
- [x] Google Analytics 4 (GA4): configuração do funil de E-commerce Avançado: `view_item -> add_to_cart -> begin_checkout -> purchase`.
- [ ] Acompanhamento de Conversões Otimizado (Enhanced Conversions): ativação no Google Ads para enviar dados criptografados e reduzir perda de rastreamento por cookies.
- [ ] Meta API de Conversão (CAPI): configuração do rastreamento do lado do servidor para Instagram/Facebook Ads.
- [ ] Server-Side Tagging: migração do GTM Web para GTM Server-Side em Google Cloud/AWS para melhorar precisão de dados e performance.
- [ ] Modelagem de Atribuição Baseada em Dados (Data-Driven): alteração no GA4 e Google Ads para entender jornada multicanal.

## 2. Ferramentas de Atração e Mídia Paga

- [x] Google Merchant Center (GMC): criação da conta e envio de feed automatizado de produtos via plataforma de e-commerce.
- [ ] Google Shopping / Performance Max (PMax): ativação de campanhas inteligentes em Pesquisa, Shopping, YouTube e Gmail.
- [ ] Otimização para Novo Cliente na PMax: configuração de meta avançada para priorizar usuários inéditos.
- [ ] Google CSS (Comparison Shopping Services): migração para parceiro CSS homologado quando aplicável, buscando reduzir custo de leilão no Shopping.
- [ ] Meta Ads com foco em vídeo: campanhas de conversão em Instagram/Facebook com criativos curtos demonstrando produto em uso real.
- [ ] Campanhas de Remarketing Dinâmico: reservar 10% a 15% do orçamento para reimpactar visitantes, carrinho abandonado e visualizações de produto.
- [ ] Microsoft Advertising (Bing Ads): importar campanhas do Google Ads para capturar tráfego de Windows com CPC potencialmente menor.

## 3. SEO Técnico e Buscadores Orgânicos

- [x] Google Search Console: vincular site e enviar `sitemap.xml` para indexação.
- [ ] IndexNow: configurar protocolo via plugin/plataforma para avisar buscadores sobre novos produtos e alterações de preço. Status atual: chave publicada; primeira submissão retornou `SiteVerificationNotCompleted`, retentar após propagação.
- [x] SEO para Google Shopping: padronizar títulos com regra item a item baseada em marca real, nome real, modelo/SKU útil e atributos técnicos.
- [x] SEO de imagens: nomear arquivos de forma descritiva e preencher `alt text`.
- [x] Dados Estruturados de Produto (Schema Markup): inserir Schema.org para rich snippets com preço, avaliação e estoque.
- [x] FAQ Schema: criar perguntas e respostas em categorias/produtos com marcação técnica.
- [x] SEO Preditivo (Grafos de Conhecimento): estruturar entidades semânticas como loja, produto, material e uso.

## 4. Experiência, Performance e Conversão (CRO)

- [x] Otimização de Performance Web (PageSpeed Insights): garantir LCP saudável com WebP, compressão de imagens e adiamento de scripts pesados.
- [ ] CDN avançada com Edge Computing: usar soluções como Cloudflare Workers para personalização/testes A/B na borda.
- [ ] Mapas de calor e gravação de sessão: usar Hotjar ou Microsoft Clarity para detectar travamentos e confusões no layout.
- [x] Busca interna inteligente por IA: usar ferramentas como SmartHint ou Searchanise com busca fonética, correção de erros e vitrines dinâmicas.
- [ ] Checkout transparente (One-Page Checkout): simplificar pagamento em uma única página.
- [ ] Pix automatizado com desconto: configurar gatilho visual claro oferecendo desconto no Pix.

## 5. Logística, Ecossistema e Retenção (LTV)

- [ ] Gateways de frete integrados: Melhor Envio, Frenet ou Kangu para múltiplos prazos e transportadoras.
- [ ] Olist ERP (Hub de Canais): unificar estoques e publicar catálogo em Mercado Livre, Shopee e Amazon.
- [ ] Estratégia inbound de caixa: inserir panfletos físicos com QR Code e cupom para migrar clientes de marketplace para site próprio.
- [ ] Prova social automatizada: integrar status de pedido entregue do Olist ERP com Trustvox, Opinions ou Loox.
- [ ] Carrinho abandonado via WhatsApp/e-mail: configurar réguas automáticas com Voxuy, Klaviyo ou RD Station.

## 6. Infraestrutura de Dados Avançada

- [ ] CDPs (Customer Data Platforms): Segment ou Jitsu para centralizar comportamento, ERP e anúncios.
- [ ] Otimização de lances por lucro real: cruzar dados financeiros do Olist ERP com Google Ads API e BigQuery para otimizar por margem.
- [ ] Data Clean Rooms: ambientes como Snowflake para cruzar bases de clientes e mídia com governança/LGPD.
- [ ] Otimização de feed baseada no clima: usar DataFeedWatch ou regras próprias com API de previsão do tempo para ajustar lances/produtos.

## Status Inicial Registrado

- [x] Rastreamento local GA4/GTM implementado no código.
- [x] Eventos e-commerce locais adicionados para `view_item`, `add_to_cart`, `begin_checkout`, `purchase` e busca.
- [x] Feed Google Merchant local melhorado com disponibilidade, imagens adicionais e tipo de produto.
- [x] Sitemap local validado com namespace de imagens.
- [x] MCP local `google-ads-readonly` configurado para diagnóstico e revisão via API.
- [ ] Revisão da campanha ativa no Google Ads pendente por bloqueio de política em `ads.google.com` neste PC e ausência de credenciais API reais no `.env`.

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
