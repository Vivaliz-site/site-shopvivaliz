# 🚀 Google Ads / GA4 Tracking - Setup Completo

**Data de Implementação**: 2026-08-05  
**Status**: ✅ Fases 1 & 2 Implementadas  
**Ambiente**: Staging/Produção pronto para ativação

---

## 📋 Resumo do que foi implementado

### Fase 1: Tracking Básico ✅
- ✅ `analytics-tracking.php` incluído em todas as páginas via `head-analytics.php`
- ✅ GA4 Measurement Protocol funcional
- ✅ Facebook Pixel e TikTok Pixel suportados
- ✅ Consentimento privacy respeitado

### Fase 2: Conversão Server-Side ✅
- ✅ Captura de `client_id`, `gclid`, UTM parameters
- ✅ Persistência no arquivo JSON do pedido
- ✅ GA4 server-side purchase tracking via webhook
- ✅ Dispara APENAS quando pagamento aprovado (não infla ROAS)
- ✅ Página de confirmação criada: `/pedido-confirmado`

---

## 🔧 Configuração Necessária

### 1️⃣ Valores REAIS que você precisa conseguir

**Google Analytics 4 (GA4)**:
```
GA4_ID:     G-XXXXXXXXXX (seu Measurement ID)
GA4_SECRET: xxxxxxxxxxxxxxxxxxxxxxxx... (~40 caracteres)
```

**Onde obter**:
1. Acesse: Google Analytics 4 > Admin (ícone de engrenagem)
2. Selecione sua Property e clique "Data Streams"
3. Clique no seu stream Web
4. Copie o "Measurement ID" → use como GA4_ID
5. Volte em Admin > Data API > Criar novo secret
6. Copie o secret gerado → use como GA4_SECRET

**Google Ads** (OPCIONAL - leave blank se não tiver):
```
GOOGLE_ADS_CONVERSION_ID:    123456789
GOOGLE_ADS_CONVERSION_LABEL: abc-123-xyz
```

**Onde obter**:
1. Google Ads > Tools & Settings > Conversions
2. Clique em "Purchase" conversion
3. Copie "Conversion ID" e "Conversion label"

### 2️⃣ Atualizar `.env`

Edite o arquivo `.env` na raiz do projeto:

```env
# Google Analytics 4 - USE SEUS VALORES REAIS AQUI
GA4_ID=G-XXXXXXXXXX                    # Seu Measurement ID real
GA4_SECRET=xxxxxxxxxxxxx...             # Seu API Secret real (~40 chars)

# Google Ads (opcional - deixar em branco se não tem)
GOOGLE_ADS_CONVERSION_ID=123456789      # Seu Conversion ID
GOOGLE_ADS_CONVERSION_LABEL=abc-123-xyz # Seu label
```

### 3️⃣ Validar Configuração

Execute o script de validação:

```bash
php scripts/validate-tracking-config.php
```

**Esperado**:
```
✅ GA4_ID: G-XXXXXXXXXX
✅ GA4_SECRET: Configurado (56 chars)
✅ analytics-tracking.php encontrado
✅ head-analytics.php encontrado
✅ pedido-confirmado.php encontrado
```

---

## ✅ Checklist de Testes Pré-Produção

### 1. Teste do Checkout PIX (mais rápido)
```
1. Ir para /catalogo
2. Clicar em um produto → /produto
3. Clicar em "Comprar" → carrinho
4. Clicar "Finalizar Pedido" → /checkout
5. Preencher dados de cliente (nome, email, CPF, CEP)
6. Confirmar "Confirmar Pedido"
7. Modal PIX aparece
8. Verificar em GA4: Admin > Realtime > Events
   → Deve aparecer evento "page_view" em /pedido-confirmado
```

### 2. Teste do Webhook de Pagamento
```
1. Após gerar um pedido (número SV123456...), fazer pagamento PIX
2. Assim que InfinitePay confirmar pagamento, webhook é acionado
3. Verificar em GA4: Admin > Realtime > Events
   → Deve aparecer evento "purchase" com os itens do pedido
4. Verificar arquivo JSON do pedido:
   storage/orders/SV123456....json
   → Deve ter: funnel_client_id, gclid (se URL teve), utm_*
```

### 3. Teste com Google Tag Assistant
```
1. Instalar extensão: https://chrome.google.com/webstore (procure "Google Tag Assistant")
2. Abrir /checkout no Chrome com extensão ativada
3. Verificar:
   ✅ gtag.js carregou
   ✅ GA4 config tag presente (G-XXXXXXXXXX)
   ✅ Eventos being firing: page_view, view_cart, begin_checkout
```

### 4. Teste de UTM/GCLID Tracking
```
1. Acessar site com parâmetros de rastreamento:
   https://shopvivaliz.com.br/?utm_source=google&utm_medium=cpc&utm_campaign=test&gclid=test123
2. Completar checkout
3. Verificar arquivo JSON do pedido:
   storage/orders/SV123456....json
   → Deve ter:
     "funnel_client_id": "uuid-here",
     "gclid": "test123",
     "utm": {
       "source": "google",
       "medium": "cpc",
       "campaign": "test",
       "content": ""
     }
```

---

## 🔍 Verificar que está Funcionando

### No GA4 Dashboard

1. **Realtime Events** (ao vivo):
   - Ir para: GA4 > Reports > Realtime
   - Deve mostrar eventos quando cliente navega

2. **Purchase Event** (após pagamento):
   - Ir para: GA4 > Reports > Realtime > Events
   - Procurar por "purchase" event
   - Deve mostrar: transaction_id, value, items[]

3. **Conversions** (relatório):
   - Ir para: GA4 > Reports > Conversions
   - Deve aparecer "Purchase" conversions conforme pedidos forem feitos

### No Google Ads (se configurado)

1. Ir para: Google Ads > Tools & Settings > Conversions
2. Clicar em "Purchase"
3. Ir para "Event setup" ou "Conversion tracking code"
4. Verificar que está "Active" e "Healthy"

---

## 🚀 Deploy em Produção

### Antes de fazer deploy:

1. ✅ Todos os 4 testes acima passando
2. ✅ GA4 recebendo eventos corretamente
3. ✅ Google Tag Assistant mostrando gtag.js
4. ✅ Arquivo JSON do pedido contém tracking data

### Deploy:

```bash
# Apenas push para production (já foi para main)
git log --oneline | head -5  # Verificar commits

# Seu processo de deploy padrão (Vercel, SSH, etc)
# O arquivo .env já deve estar configurado no servidor
```

### Pós-deploy:

1. Acessar site em produção
2. Completar 2-3 pedidos PIX
3. Aguardar 10-15 minutos
4. Verificar GA4 realtime novamente
5. Criar tráfego test com UTM params e simular pagamento

---

## 📊 Arquitetura da Solução

```
┌─────────────────────────────────────────────────────┐
│ Cliente Browser                                      │
├─────────────────────────────────────────────────────┤
│ js/shopvivaliz-google-events.js                      │
│ - Captura: client_id, gclid, utm_* (localStorage)   │
│ - Dispara: page_view, view_item, add_to_cart, etc   │
│ - gtag.js (Google)                                   │
│ - fbq.js (Facebook)                                  │
│ - ttq.js (TikTok)                                    │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│ Checkout (client-side)                               │
├─────────────────────────────────────────────────────┤
│ checkout.php                                         │
│ - Lê client_id, gclid, utm_* do localStorage        │
│ - POST para /api/orders/create.php                  │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│ Order Creation (server-side)                        │
├─────────────────────────────────────────────────────┤
│ api/orders/process-validated.php                    │
│ - Persiste: funnel_client_id, gclid, utm_*         │
│ - Salva JSON do pedido                              │
│ - Cria página /pedido-confirmado                    │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│ Payment (InfinitePay/MercadoPago)                   │
├─────────────────────────────────────────────────────┤
│ Cliente faz pagamento (PIX, boleto, etc)            │
└─────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────┐
│ Webhook (server-side - CANONICAL EVENT)             │
├─────────────────────────────────────────────────────┤
│ api/webhook-infinitepay.php                         │
│ - Lê: payment_approved                              │
│ - GA4 Measurement Protocol:                         │
│   POST https://www.google-analytics.com/mp/collect  │
│   { client_id, event: "purchase", items[], value }  │
│ - Dispara APENAS quando payment_approved            │
└─────────────────────────────────────────────────────┘
                         ↓
        ┌─────────────────────────────────┐
        │ GA4 Dashboard (Realtime + Reports)
        │ Google Ads (Conversions)
        │ Facebook (Conversions)
        │ TikTok (Pixel)
        └─────────────────────────────────┘
```

---

## 🐛 Troubleshooting

### GA4_SECRET não funciona
**Sintoma**: Script de validação falha ao disparar evento GA4
**Solução**:
1. Verificar que o secret foi copiado completo (sem espaços)
2. Verificar que GA4_ID está correto
3. Recriar o secret em GA4 > Admin > Data API

### Eventos não aparecem no GA4
**Sintoma**: Checkout completa mas GA4 não recebe evento purchase
**Solução**:
1. Verificar: GA4 > Admin > Data Streams > Web > Data Collection (active)
2. Verificar: Arquivo JSON do pedido tem `funnel_client_id`
3. Verificar logs: `error_log` do servidor (verificar erros no POST para GA4)
4. Usar Google Tag Assistant para validar gtag.js

### Cliente ID não está sendo capturado
**Sintoma**: Pedido salvo mas sem `funnel_client_id`
**Solução**:
1. Verificar `localStorage.sv_funnel_client_v1` no browser console
2. Verificar que privacy consent foi dado (`sv_privacy_consent=accepted`)
3. Verificar que js/shopvivaliz-google-events.js carregou (browser dev tools)

---

## 📞 Suporte

Para problemas:
1. Rodar `php scripts/validate-tracking-config.php`
2. Checar Google Tag Assistant no browser
3. Revisar logs do servidor
4. Consultar GOOGLE-ADS-AUDIT.md para referência de arquitetura

---

**Implementado por**: Claude Code (2026-08-05)  
**Status**: Production-ready (valores env vars necessários)
