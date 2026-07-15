# 📊 Auditoria Google Ads + Fluxo Ecommerce - ShopVivaliz

**Data**: 2026-07-13  
**Status**: ⚠️ CRÍTICO - Tracking implementado mas NÃO ATIVO  
**Recomendação**: NÃO colocar em produção com campanhas Google Ads até corrigir

---

## 🔴 PROBLEMAS CRÍTICOS ENCONTRADOS

### 1. **Tracking Code NÃO Inserido nas Páginas**
- ✅ `analytics-tracking.php` implementado com GA4, Facebook e TikTok
- ❌ **NÃO está incluído em nenhuma página** (index.php, checkout.php, etc)
- ❌ **Sem código gtag.js** no `<head>` das páginas
- **Impacto**: Zero conversões rastreadas, dados de Google Ads não coletados

### 2. **Variáveis de Ambiente NÃO Configuradas**
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

## 🚨 PLANO DE CORREÇÃO (Prioritário)

### FASE 1: Ativar Tracking Básico (2 horas)
1. Incluir `analytics-tracking.php` no head de todas as páginas
2. Inserir `<?php echo get_tracking_code(); ?>` antes de `</head>`
3. Criar `page-head.php` include padrão para reutilização
4. Configurar variáveis de ambiente (.env):
   - `GA4_ID` = ID do seu Google Analytics 4
   - `GA4_SECRET` = API secret do GA4
   - `GOOGLE_ADS_ID` = ID de conversão do Google Ads

### FASE 2: Instrumentar Fluxo (3 horas)
1. Adicionar `track_page_view()` em todas as páginas principais:
   - Home, Categoria, Produto, Carrinho, Checkout
2. Adicionar `track_view_item()` em página de produto
3. Adicionar `track_add_to_cart()` no carrinho
4. Criar página de confirmação com `track_purchase()`

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

