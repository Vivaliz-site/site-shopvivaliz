# 🚀 Shopee Integration - Setup Guide

## 📂 Estrutura de Arquivos

```
api/shopee-integration/
├── README.md                 # Documentação principal
├── CREDENTIALS.json          # Credenciais e tokens (não commit!)
├── API_REFERENCE.md          # Referência de endpoints
├── SETUP_GUIDE.md           # Este arquivo
│
└── scripts/
    ├── run_playwright.py    # Login automático no Shopee
    ├── get_token.py         # Obter tokens via OAuth
    ├── test_shopee_api.py   # Testar conexão com API
    └── test_final.py        # Teste final simplificado
```

## 🔑 Credenciais (Nunca commit!)

⚠️ **IMPORTANTE**: Nunca faça commit de credenciais! Use GitHub Secrets.

### Credenciais Ativas
```
Shop ID: 227695582
Partner ID: 1237032
Partner Key: shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d

Sandbox User: SANDBOX.b6fb03003426929be0c1
Sandbox Pass: 56194122e737c5cd

Current Access Token: 535a586d674844627874525179787554 (expira em 4h)
Current Refresh Token: 4f59435665486e4b5a51596e46656e4f
```

## ⚙️ Instalação

### 1. Instalar Dependências
```bash
pip install playwright requests
python -m playwright install chromium
```

### 2. Configurar GitHub Secrets

Ambos repositórios já têm os secrets criados:
- fredmourao-ai/site-shopvivaliz
- fredmourao-ai/-shopvivaliz-pipeline

```bash
# Listar secrets (exemplo)
gh secret list --repo fredmourao-ai/site-shopvivaliz | grep SHOPEE
```

## 🎯 Workflow de Uso

### Fluxo 1: Renovar Token (A cada 4 horas)

```bash
# 1. Renovar via refresh token
python api/shopee-integration/scripts/refresh_token.py

# 2. Atualizar secrets no GitHub
gh secret set SHOPEE_ACCESS_TOKEN --body "novo_token" \
  --repo fredmourao-ai/site-shopvivaliz
```

### Fluxo 2: Nova Autorização (Se token expirar)

```bash
# 1. Fazer login automático
python api/shopee-integration/scripts/run_playwright.py
# → Obterá authorization code

# 2. Trocar por tokens
python api/shopee-integration/scripts/get_token.py
# → Obterá access_token e refresh_token

# 3. Atualizar secrets
gh secret set SHOPEE_ACCESS_TOKEN --body "novo_token" \
  --repo fredmourao-ai/site-shopvivaliz
gh secret set SHOPEE_REFRESH_TOKEN --body "novo_refresh" \
  --repo fredmourao-ai/site-shopvivaliz
```

### Fluxo 3: Testar Conexão

```bash
# Testar API
python api/shopee-integration/scripts/test_shopee_api.py

# Ou teste simplificado
python api/shopee-integration/scripts/test_final.py
```

## 📊 GitHub Actions Workflow

Exemplo de workflow para sincronizar com Shopee:

```yaml
name: Shopee Sync

on:
  schedule:
    - cron: '0 */4 * * *'  # A cada 4 horas
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
        run: |
          pip install requests
      
      - name: Sync Shopee Products
        env:
          SHOPEE_ACCESS_TOKEN: ${{ secrets.SHOPEE_ACCESS_TOKEN }}
          SHOPEE_REFRESH_TOKEN: ${{ secrets.SHOPEE_REFRESH_TOKEN }}
          SHOPEE_SHOP_ID: ${{ secrets.SHOPEE_SHOP_ID }}
          SHOPEE_PARTNER_ID: ${{ secrets.SHOPEE_TEST_PARTNER_ID }}
          SHOPEE_PARTNER_KEY: ${{ secrets.SHOPEE_TEST_PARTNER_KEY }}
        run: |
          python api/shopee-integration/scripts/sync_shopee.py
```

## 🔄 Renovação Automática de Token

### Opção 1: Via GitHub Actions

```yaml
name: Refresh Shopee Token

on:
  schedule:
    - cron: '0 */3 * * *'  # A cada 3 horas

jobs:
  refresh:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Set up Python
        uses: actions/setup-python@v4
        with:
          python-version: '3.11'
      - name: Install dependencies
        run: pip install requests
      - name: Refresh token
        env:
          SHOPEE_REFRESH_TOKEN: ${{ secrets.SHOPEE_REFRESH_TOKEN }}
          SHOPEE_PARTNER_ID: ${{ secrets.SHOPEE_TEST_PARTNER_ID }}
          SHOPEE_PARTNER_KEY: ${{ secrets.SHOPEE_TEST_PARTNER_KEY }}
        run: |
          python -c "
          import requests, hmac, hashlib, time, os, json
          
          partner_id = int(os.getenv('SHOPEE_PARTNER_ID'))
          partner_key = os.getenv('SHOPEE_PARTNER_KEY')
          refresh_token = os.getenv('SHOPEE_REFRESH_TOKEN')
          
          path = '/api/v2/auth/token/refresh'
          timestamp = int(time.time())
          base = f'{partner_id}{path}{timestamp}'
          sign = hmac.new(partner_key.encode(), base.encode(), hashlib.sha256).hexdigest()
          
          url = f'https://openplatform.sandbox.test-stable.shopee.sg{path}?partner_id={partner_id}&timestamp={timestamp}&sign={sign}'
          payload = {'refresh_token': refresh_token, 'shop_id': 227695582, 'partner_id': partner_id}
          
          r = requests.post(url, json=payload)
          data = r.json()
          
          if data.get('access_token'):
              print(f'::set-secret name=SHOPEE_ACCESS_TOKEN::{data[\"access_token\"]}')
          "
```

### Opção 2: Via Azure DevOps / outras plataformas

Configure cron job que execute:
```bash
python api/shopee-integration/scripts/refresh_token.py
```

## 🧪 Testing

### Teste 1: Conexão Básica
```bash
python api/shopee-integration/scripts/test_final.py
```

### Teste 2: Todos os Endpoints
```bash
python api/shopee-integration/scripts/test_shopee_api.py
```

### Teste 3: Obter Info da Loja
```python
import requests, hmac, hashlib, time, os

PARTNER_ID = int(os.getenv('SHOPEE_TEST_PARTNER_ID'))
PARTNER_KEY = os.getenv('SHOPEE_TEST_PARTNER_KEY')
ACCESS_TOKEN = os.getenv('SHOPEE_ACCESS_TOKEN')
SHOP_ID = int(os.getenv('SHOPEE_SHOP_ID'))

path = '/api/v2/shop/get_shop_info'
timestamp = int(time.time())
base = f'{PARTNER_ID}{path}{timestamp}'
sign = hmac.new(PARTNER_KEY.encode(), base.encode(), hashlib.sha256).hexdigest()

url = f'https://openplatform.sandbox.test-stable.shopee.sg{path}?partner_id={PARTNER_ID}&timestamp={timestamp}&sign={sign}&access_token={ACCESS_TOKEN}&shop_id={SHOP_ID}'

r = requests.get(url)
print(r.json())
```

## 📋 Checklist

- [ ] Credenciais.json criado (não comitado)
- [ ] Scripts testados localmente
- [ ] GitHub Secrets criados em ambos repositórios
- [ ] Workflow GitHub Actions configurado
- [ ] Token refresh automático implementado
- [ ] Testes passando
- [ ] Documentação atualizada

## 🐛 Troubleshooting

### Token expirado
```bash
python api/shopee-integration/scripts/get_token.py
# Atualizar secrets
```

### Erro de assinatura
Verifique:
1. Partner ID correto: `1237032`
2. Partner Key: `shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d`
3. Timestamp atual em Unix
4. Formato: `{PARTNER_ID}{path}{timestamp}`

### API retorna 403 Forbidden
- Verifique se access_token está válido
- Verifique se shop_id está correto: `227695582`
- Verifique assinatura

## 📚 Referências

- [Shopee Open Platform](https://open.shopee.com/)
- [API Documentation](./API_REFERENCE.md)
- [Credentials](./CREDENTIALS.json)

## ✅ Status Atual

- ✅ OAuth autorização completa
- ✅ Access Token obtido
- ✅ Refresh Token armazenado
- ✅ GitHub Secrets criados
- ✅ Scripts funcionais
- ⏳ Renovação automática (configurar)

---

**Última atualização**: 2026-06-29  
**Versão**: 1.0  
**Ambientes**: Sandbox (teste) + Production (futuro)
