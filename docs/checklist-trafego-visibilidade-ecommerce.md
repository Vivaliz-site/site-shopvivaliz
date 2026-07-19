# Checklist de Tráfego e Visibilidade Para Site Próprio (E-commerce)

Documento-base para priorizar implementação de rastreamento, aquisição de tráfego, SEO, conversão, retenção e infraestrutura de dados da ShopVivaliz.

## 1. Estrutura Técnica e Configurações de Rastreamento

- [ ] Google Tag Manager (GTM): instalação centralizada para gerenciar tags sem reduzir a velocidade do site.
- [ ] Google Analytics 4 (GA4): configuração do funil de E-commerce Avançado: `view_item -> add_to_cart -> begin_checkout -> purchase`.
- [ ] Acompanhamento de Conversões Otimizado (Enhanced Conversions): ativação no Google Ads para enviar dados criptografados e reduzir perda de rastreamento por cookies.
- [ ] Meta API de Conversão (CAPI): configuração do rastreamento do lado do servidor para Instagram/Facebook Ads.
- [ ] Server-Side Tagging: migração do GTM Web para GTM Server-Side em Google Cloud/AWS para melhorar precisão de dados e performance.
- [ ] Modelagem de Atribuição Baseada em Dados (Data-Driven): alteração no GA4 e Google Ads para entender jornada multicanal.

## 2. Ferramentas de Atração e Mídia Paga

- [ ] Google Merchant Center (GMC): criação da conta e envio de feed automatizado de produtos via plataforma de e-commerce.
- [ ] Google Shopping / Performance Max (PMax): ativação de campanhas inteligentes em Pesquisa, Shopping, YouTube e Gmail.
- [ ] Otimização para Novo Cliente na PMax: configuração de meta avançada para priorizar usuários inéditos.
- [ ] Google CSS (Comparison Shopping Services): migração para parceiro CSS homologado quando aplicável, buscando reduzir custo de leilão no Shopping.
- [ ] Meta Ads com foco em vídeo: campanhas de conversão em Instagram/Facebook com criativos curtos demonstrando produto em uso real.
- [ ] Campanhas de Remarketing Dinâmico: reservar 10% a 15% do orçamento para reimpactar visitantes, carrinho abandonado e visualizações de produto.
- [ ] Microsoft Advertising (Bing Ads): importar campanhas do Google Ads para capturar tráfego de Windows com CPC potencialmente menor.

## 3. SEO Técnico e Buscadores Orgânicos

- [ ] Google Search Console: vincular site e enviar `sitemap.xml` para indexação.
- [ ] IndexNow: configurar protocolo via plugin/plataforma para avisar buscadores sobre novos produtos e alterações de preço.
- [ ] SEO para Google Shopping: padronizar títulos com a regra `[Marca + Categoria + Modelo + Atributo de tamanho/cor]`.
- [ ] SEO de imagens: nomear arquivos de forma descritiva e preencher `alt text`.
- [ ] Dados Estruturados de Produto (Schema Markup): inserir Schema.org para rich snippets com preço, avaliação e estoque.
- [ ] FAQ Schema: criar perguntas e respostas em categorias/produtos com marcação técnica.
- [ ] SEO Preditivo (Grafos de Conhecimento): estruturar entidades semânticas como loja, produto, material e uso.

## 4. Experiência, Performance e Conversão (CRO)

- [ ] Otimização de Performance Web (PageSpeed Insights): garantir LCP saudável com WebP, compressão de imagens e adiamento de scripts pesados.
- [ ] CDN avançada com Edge Computing: usar soluções como Cloudflare Workers para personalização/testes A/B na borda.
- [ ] Mapas de calor e gravação de sessão: usar Hotjar ou Microsoft Clarity para detectar travamentos e confusões no layout.
- [ ] Busca interna inteligente por IA: usar ferramentas como SmartHint ou Searchanise com busca fonética, correção de erros e vitrines dinâmicas.
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
