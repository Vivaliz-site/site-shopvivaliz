# 📊 Auditoria Google Ads + Fluxo Ecommerce - ShopVivaliz

**Data**: 2026-07-13 | **Atualizado**: 2026-08-05  
**Status**: ✅ FASES 1 & 2 IMPLEMENTADAS - Awaiting configuration de env vars  
**Recomendação**: Configure variáveis de ambiente e teste em staging antes de produção

---

## 🟡 STATUS DA IMPLEMENTAÇÃO (Fase 1 & 2)

### ✅ CONCLUÍDO (2026-08-05)

#### 1. **Tracking Code Inserido nas Páginas**
- ✅ `analytics-tracking.php` implementado com GA4, Facebook e TikTok
- ✅ **Incluído em todas as páginas** via `head-analytics.php`
- ✅ **Código gtag.js** carregado no `<head>` de todas as páginas (checkout.php, carrinho.php, etc)
- ✅ **Funções helpers** definidas: `track_page_view()`, `track_view_item()`, `track_add_to_cart()`, `track_purchase()`
- ✅ **Server-side GA4** via Measurement Protocol implementado

#### 2. **Captura & Persistência de Dados de Tracking**
- ✅ **funnel_client_id** capturado do `localStorage.sv_funnel_client_v1` e persistido no pedido
- ✅ **gclid** capturado da URL ou localStorage e persistido
- ✅ **UTM parameters** (utm_source, utm_medium, utm_campaign, utm_content) capturados e persistidos
- ✅ **Página de confirmação** criada em `/pedido-confirmado`

#### 3. **Tracking Server-Side de Purchase**
- ✅ **GA4 Measurement Protocol** disparado via webhook quando `payment_approved`
- ✅ Apenas dispara quando pagamento é realmente aprovado (não em criação do pedido)
- ✅ Mantém padrão de ROAS não inflado

### 🟠 AGUARDANDO CONFIGURAÇÃO (Variáveis de Ambiente)
```
GA4_ID              ❌ Vazio/Placeholder 'G-XXXXXXXXXX'
GA4_SECRET          ❌ Não configurado
GOOGLE_ADS_ID       ❌ Não existe
FACEBOOK_PIXEL      ❌ Não configurado
FACEBOOK_ACCESS_TOKEN ❌ Não configurado
TIKTOK_PIXEL        ❌ Não configurado
TIKTOK_PIXEL_TOKEN  ❌ Não configurado
```

### 3. **Fluxo de Conversão NÃO Instrumentado**
- ✅ Funções preparadas: `track_page_view()`, `track_add_to_cart()`, `track_purchase()`
- ❌ **Nenhuma chamada destas funções** no código
- ❌ Checkout.php não rastreia adição ao carrinho
- ❌ Sem página de "Thank You" / confirmação com pixel de compra
- ❌ Sem rastreamento de eventos de produto

### 4. **UTM Parameters NÃO Tratados**
- ❌ Sem captura de `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`
- ❌ Sem vinculação de UTM params ao GA4 events
- ❌ Sem persistência de UTM no carrinho/pedido

### 5. **Página de Confirmação de Pedido Ausente**
- ❌ Sem URL específica como `order-confirmation.php` ou `/pedido-confirmado`
- ❌ Sem evento de "Purchase" após pagamento
- ❌ Sem rastreamento de revenue

---

## ✅ O QUE ESTÁ IMPLEMENTADO (Parcial)

### Código Backend Pronto
```
/includes/analytics-tracking.php  - Classe completa
  ├─ GA4 Measurement Protocol API ✅
  ├─ Facebook Conversion API ✅
  ├─ TikTok Pixel ✅
  └─ Eventos: page_view, view_item, add_to_cart, purchase, search ✅

/checkout.php
  ├─ Formulário de checkout ✅
  └─ Sem tracking de eventos ❌
```

### Funções Helper Disponíveis
```php
track_page_view($title, $path)          // NÃO USADA
track_view_item($product)               // NÃO USADA
track_add_to_cart($product, $qty)       // NÃO USADA
track_purchase($order)                  // NÃO USADA
track_search($term, $count)             // NÃO USADA
get_tracking_code()                     // NÃO INSERIDA NO HTML
```

---

## 🔧 PRÓXIMOS PASSOS - CONFIGURAÇÃO OBRIGATÓRIA

### Variáveis de Ambiente Necessárias (Configurar antes de usar em produção)

```env
# GA4 (obrigatório para server-side tracking)
GA4_ID=G-XXXXXXXXX              # Seu Measurement ID do GA4
GA4_SECRET=abc123def456...      # API Secret gerado em GA4 > Admin > Data API

# Google Ads (opcional, mas recomendado)
GOOGLE_ADS_CONVERSION_ID=123456789      # Seu Conversion ID
GOOGLE_ADS_CONVERSION_LABEL=abc-123-xyz # Label da conversão Purchase

# Consent (obrigatório para respeitar privacidade)
# Já está implementado - verificar se sv_privacy_consent cookie está funcionando
```

### Aonde obter as credenciais:

1. **GA4_ID**: Google Analytics 4 → Admin → Data Streams → Seu Web Stream → Measurement ID
2. **GA4_SECRET**: Google Analytics 4 → Admin → Data API → Criar novo secret
3. **GOOGLE_ADS_CONVERSION_ID/LABEL**: Google Ads → Tools & Settings → Conversions → Selecione "Purchase" → Copie ID e Label

### Checklist pré-produção após configurar env vars:

- [ ] Adicionar GA4_ID ao arquivo `.env` (ou runtime-secrets.php)
- [ ] Adicionar GA4_SECRET ao arquivo `.env`
- [ ] Testar checkout com um pedido PIX (client-side tracking)
- [ ] Confirmar pagamento e validar que GA4 recebeu o evento "purchase"
- [ ] Usar Google Tag Assistant para validar que gtag.js está disparando
- [ ] Validar que funnel_client_id está sendo salvo nos pedidos (verificar storage/orders/)
- [ ] Testar com URL contendo gclid para validar persistência
- [ ] Deploy em staging para QA
- [ ] Deploy em produção

---

## 📋 PLANO ORIGINAL (Referência)

### FASE 1: Ativar Tracking Básico ✅ FEITO (2026-08-05)
- ✅ `analytics-tracking.php` já incluído via `head-analytics.php` em todas as páginas
- ✅ `get_tracking_code()` dispara gtag.js e GA4/Facebook/TikTok pixels no `<head>`
- ✅ Head include padrão criado em `includes/head-analytics.php` (reutilizável em todas as páginas)
- ✅ Funções helpers de tracking definidas e prontas para uso
- ⏳ Variáveis de ambiente requerem configuração manual (GA4_ID, GA4_SECRET, etc) — ver seção acima

### FASE 2: Instrumentar Fluxo ✅ FEITO (2026-08-05)
- ✅ Tracking client-side já funciona via `shopvivaliz-google-events.js`
  - `view_item` disparado na página de produto
  - `view_item_list` disparado no catálogo
  - `add_to_cart` disparado via click listener
  - `view_cart` disparado no carrinho
  - `begin_checkout` disparado no checkout
- ✅ Página de confirmação criada em `/pedido-confirmado` (order-confirmation.php)
- ✅ Tracking server-side de purchase implementado:
  - GA4 Measurement Protocol disparado via webhook quando `payment_approved`
  - Apenas após pagamento confirmado (não infla ROAS)
  - Usa `funnel_client_id` persistido no pedido
- ✅ Captura de UTM params (utm_source, utm_medium, utm_campaign, utm_content)
- ✅ Captura de gclid (Google Click ID)
- ✅ Persistência de dados no arquivo JSON do pedido para auditoria

### FASE 3: Google Ads Setup (1 hora)
1. Criar conversão "Purchase" no Google Ads
2. Gerar Google Ads Conversion ID + Conversion Label
3. Adicionar ao `analytics-tracking.php`
4. Testar com Google Tag Assistant

### FASE 4: Remarketing (1 hora)
1. Adicionar Google Ads Remarketing tag
2. Configurar Audiences no Google Ads
3. Testar pixel firing

---

## 📋 CHECKLIST PRÉ-PRODUÇÃO

### Google Analytics 4
- [ ] GA4 Property criada
- [ ] Measurement ID (G-XXXXXXXXX) obtido
- [ ] API Secret gerado
- [ ] Conversões configuradas (Purchase, Add to Cart, etc)
- [ ] E-commerce events habilitados
- [ ] Tag gátag.js disparando em todas as páginas

### Google Ads
- [ ] Conta Google Ads ativa
- [ ] Conversion tracking ID criado
- [ ] Purchase conversion configurada
- [ ] UTM tracking estruturado
- [ ] Google Ads tag (gtag.js) disparando

### Facebook Pixel
- [ ] Pixel ID obtido
- [ ] Access Token gerado
- [ ] Eventos de compra configurados
- [ ] Standard events mapeados

### Fluxo Ecommerce
- [ ] Página de produto com tracking
- [ ] Carrinho com Add to Cart event
- [ ] Checkout com rastreamento
- [ ] Página de confirmação com Purchase event
- [ ] Order confirmation email

### UTM Tracking
- [ ] URL builder configurado
- [ ] Campanhas com UTM parameters
- [ ] Dashboard de origin/source em GA4
- [ ] Vinculação GA4 ↔ Google Ads

---

## 🔗 Integrações Necessárias para Produção

### 1. Google Analytics 4
```env
GA4_ID=G-XXXXXXXXX              # ID do GA4
GA4_SECRET=abc123def456...      # API Secret
```
**Onde obter**: Google Analytics → Admin → Data Streams → Web

### 2. Google Ads Conversion Tracking
```env
GOOGLE_ADS_CONVERSION_ID=123456789
GOOGLE_ADS_CONVERSION_LABEL=abc123-def456
```
**Onde obter**: Google Ads → Tools → Conversions

### 3. Facebook Pixel
```env
FACEBOOK_PIXEL=1234567890
FACEBOOK_ACCESS_TOKEN=EAA...
```
**Onde obter**: Facebook Business → Pixels

### 4. TikTok Pixel
```env
TIKTOK_PIXEL=TT1234567890
TIKTOK_PIXEL_TOKEN=c...
```
**Onde obter**: TikTok Ads → Event Manager

---

## 📊 Fluxo de Conversão Esperado

```
Visitante Google Ads
  ↓
Landing Page (GA4: page_view)
  ↓
Clica em Produto (GA4: view_item)
  ↓
Adiciona ao Carrinho (GA4: add_to_cart)
  ↓
Vai para Checkout (GA4: page_view)
  ↓
Completa Compra (GA4: purchase)
  ↓
Confirmação (Google Ads: conversion!)
  ↓
Relatório: Conversão atribuída ao anúncio
```

---

## 🧪 Teste de Pixel

Depois de ativar, testar com:
1. **Google Tag Assistant**: https://tagassistant.google.com/
2. **Facebook Pixel Helper**: Chrome Extension
3. **TikTok Pixel Helper**: Chrome Extension
4. **Conversion Tracking**: Google Ads → Tools → Event Snippets

---

## ⏰ Timeline Recomendado

- **Hoje**: Ativar tracking básico (Fase 1)
- **Amanhã**: Instrumentar fluxo (Fase 2)  
- **3º dia**: Setup Google Ads (Fase 3)
- **4º dia**: Remarketing + Testes
- **5º dia**: Deploy em produção + Campanhas

---

## 💰 Impacto Financeiro

**Sem tracking**: 
- ❌ Não sabe quais anúncios vendem
- ❌ Não consegue otimizar ROI
- ❌ Gasta em anúncios mas não rastreia conversão
- ❌ Estimativa: Perda de 30-50% do ROI

**Com tracking completo**:
- ✅ Sabe qual anúncio gera conversão
- ✅ Otimiza bids automaticamente
- ✅ Remarketing eficiente
- ✅ Estimativa: +40% de ROAS

---

**Status Final**: 🚨 NÃO APTO PARA PRODUÇÃO COM GOOGLE ADS  
**Ação Necessária**: Implementar Fase 1 e 2 antes de colocar em produção

