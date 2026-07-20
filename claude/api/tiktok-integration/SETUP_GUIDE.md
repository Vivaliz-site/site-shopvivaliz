# 🎵 TikTok Shop API - Setup Guide

## 📋 Credenciais

```
App Key: 6kf502maarj2k
App Secret: f0a2a1e58a7le4ca8b5f0f7fdfdb2o0ebee06c
Redirect URL: https://shopvivaliz.com.br
```

## ⚙️ Instalação

### 1. Instalar Dependências
```bash
pip install requests
```

### 2. Workflow de Autorização OAuth

#### Passo 1: Obter Authorization Code
Acesse:
```
https://partner.tiktokshop.com/authorize?
  client_id=6kf502maarj2k&
  redirect_uri=https://shopvivaliz.com.br&
  scope=shop.basic&
  response_type=code
```

#### Passo 2: Capturar o código
Após autorizar, você será redirecionado com:
```
https://shopvivaliz.com.br?code=XXXX&shop_id=XXXX
```

#### Passo 3: Trocar código por token
```bash
python api/tiktok-integration/scripts/get_access_token.py
```

### 3. Atualizar Secrets
```bash
gh secret set TIKTOK_ACCESS_TOKEN --body "seu_token" \
  --repo fredmourao-ai/site-shopvivaliz
gh secret set TIKTOK_REFRESH_TOKEN --body "seu_refresh" \
  --repo fredmourao-ai/site-shopvivaliz
gh secret set TIKTOK_SHOP_ID --body "seu_shop_id" \
  --repo fredmourao-ai/site-shopvivaliz
```

## 📊 GitHub Actions Workflow

```yaml
name: TikTok Shop Sync

on:
  schedule:
    - cron: '0 */6 * * *'  # A cada 6 horas
  workflow_dispatch:

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Set up Python
        uses: actions/setup-python@v4
        with:
          python-version: '3.11'
      
      - name: Install dependencies
        run: pip install requests
      
      - name: Sync TikTok Products
        env:
          TIKTOK_ACCESS_TOKEN: ${{ secrets.TIKTOK_ACCESS_TOKEN }}
          TIKTOK_REFRESH_TOKEN: ${{ secrets.TIKTOK_REFRESH_TOKEN }}
          TIKTOK_SHOP_ID: ${{ secrets.TIKTOK_SHOP_ID }}
          TIKTOK_APP_KEY: ${{ secrets.TIKTOK_APP_KEY }}
          TIKTOK_APP_SECRET: ${{ secrets.TIKTOK_APP_SECRET }}
        run: |
          python api/tiktok-integration/scripts/sync_tiktok.py
```

## 🧪 Testing

### Teste 1: Obter Access Token
```bash
python api/tiktok-integration/scripts/get_access_token.py
```

### Teste 2: Testar API
```bash
python api/tiktok-integration/scripts/test_tiktok_api.py
```

## 📚 Referências

- Partner Center: https://partner.tiktokshop.com/
- Developer Docs: https://developers.tiktok.com/doc/
- API Sandbox: https://open-api.tiktokglobalshop.com

## 🔄 Renovação de Token

```bash
python api/tiktok-integration/scripts/refresh_token.py
```

---

**Status**: 🔧 Configuração  
**Data**: 2026-06-29
