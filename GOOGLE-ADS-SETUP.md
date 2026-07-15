# 🚀 Google Ads Setup - Fase 1-2 Completa

## ✅ Já Implementado

1. **Tracking inserido nas páginas**:
   - ✅ `/index.php` - GA4 + Facebook + TikTok ativo
   - ✅ `/checkout.php` - Rastreamento de checkout
   - ✅ Include automático: `includes/head-analytics.php`

2. **Variáveis de ambiente configuradas**:
   - ✅ `.env.example` com todas as credenciais necessárias
   - ✅ `analytics-tracking.php` pronta para usar

3. **Eventos implementados**:
   - ✅ `page_view` - Automaticamente em todas as páginas
   - ✅ `view_item` - Função disponível
   - ✅ `add_to_cart` - Função disponível  
   - ✅ `purchase` - Função disponível
   - ✅ `search` - Função disponível

---

## 📋 Passo 1: Obter Credenciais Google Analytics 4

### 1.1 Google Analytics 4 Setup
1. Acesse: https://analytics.google.com/
2. Clique em "Administrador" (engrenagem no canto esquerdo)
3. Selecione sua propriedade "ShopVivaliz"
4. Em "Instalação de dados", clique em "Fluxos de dados"
5. Clique em seu fluxo da Web
6. Copie o **Measurement ID** (format: `G-XXXXXXXXXX`)

### 1.2 Gerar API Secret do GA4
1. No mesmo fluxo, clique em "Segredos de medição"
2. Clique em "Criar segredo"
3. Nomeie: "ShopVivaliz API Secret"
4. Copie o valor (será um token longo)

### 1.3 Adicionar ao .env (Oracle Cloud)
```bash
ssh -i <sua-chave> ubuntu@137.131.156.17
nano /home/ubuntu/site-shopvivaliz/.env

# Adicionar:
GA4_ID=G-XXXXXXXXXX
GA4_SECRET=abc123def456...
```

---

## 📋 Passo 2: Obter Google Ads Conversion ID

### 2.1 Criar Conversão no Google Ads
1. Acesse: https://ads.google.com
2. Clique em "Ferramentas" → "Conversões"
3. Clique em "+ Conversão"
4. Tipo: "Pedido / Compra"
5. Nome: "Purchase"
6. Copie o **Conversion ID** e **Conversion Label**

### 2.2 Adicionar ao .env
```bash
GOOGLE_ADS_CONVERSION_ID=123456789
GOOGLE_ADS_CONVERSION_LABEL=abc123_def456
```

---

## 📋 Passo 3: Configurar Facebook Pixel (Opcional)

### 3.1 Criar Pixel
1. Acesse: https://business.facebook.com
2. Vá para "Pixels" (em Eventos)
3. Clique em "Criar Pixel"
4. Nome: "ShopVivaliz"
5. Copie o **Pixel ID**

### 3.2 Gerar Access Token
1. Vá para "Configurações de Negócios"
2. "Usuários" → Adicione-se
3. Gere um "Token de Acesso Longo"
4. Copie o token

### 3.3 Adicionar ao .env
```bash
FACEBOOK_PIXEL=1234567890
FACEBOOK_ACCESS_TOKEN=EAAxxxxx...
```

---

## 📋 Passo 4: Configurar TikTok Pixel (Opcional)

### 4.1 Criar Pixel TikTok
1. Acesse: https://ads.tiktok.com
2. "Eventos" → "Pixel"
3. Clique em "+ Novo Pixel"
4. Nome: "ShopVivaliz"
5. Copie o **Pixel ID**

### 4.2 Gerar Access Token
1. "Configurações" → "API Access"
2. Gere um novo token
3. Copie o token

### 4.3 Adicionar ao .env
```bash
TIKTOK_PIXEL=TT1234567890abcd
TIKTOK_PIXEL_TOKEN=c_xxx...
```

---

## 🧪 Testar Tracking

### Google Tag Assistant
1. Instale: https://tagassistant.google.com/
2. Visite: https://dev.shopvivaliz.com.br
3. Procure por "Google Analytics" e "Google Ads" ✅

### Facebook Pixel Helper
1. Instale Chrome Extension: "Facebook Pixel Helper"
2. Visite: https://dev.shopvivaliz.com.br
3. Deveria mostrar "Pixel ativo" ✅

### Verificar no GA4
1. Acesse: https://analytics.google.com
2. Clique em "Relatórios em tempo real"
3. Visite seu site
4. Deveria aparecer 1 usuário ativo ✅

---

## 📊 Próximas Etapas (Depois de Ativar)

### Fase 3: Instrumentação Completa
- [ ] Adicionar `track_view_item()` em página de produto
- [ ] Adicionar `track_add_to_cart()` no carrinho
- [ ] Criar página de confirmação com `track_purchase()`
- [ ] Testar compra completa

### Fase 4: Google Ads Otimizações
- [ ] Setup de Remarketing Audience
- [ ] Setup de Similar Audiences
- [ ] Configurar Smart Bidding
- [ ] Criar campanhas de Performance Max

### Fase 5: Análise & Otimização
- [ ] Dashboard de conversão por fonte
- [ ] ROI por campaña
- [ ] Teste A/B de landing pages
- [ ] Otimizar bids baseado em performance

---

## 💡 Dica: Usar Script de Setup Automático

Se tiver SSH configurado:
```bash
python3 /home/ubuntu/site-shopvivaliz/scripts/setup-oauth-env.py
# Ou criar script similar para Google Ads credentials
```

---

**Status**: Tracking ativado, aguardando credenciais  
**Próximos 30 minutos**: Setup das variáveis de ambiente

