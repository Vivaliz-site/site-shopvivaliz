# 🛍️ Integração Shopee - Documentação Completa

## 📂 Estrutura de Pastas

```
api/shopee-integration/
├── README.md                    (este arquivo)
├── CREDENTIALS.json            (credenciais e tokens)
├── SECRETS.json                (secrets do GitHub)
├── API_REFERENCE.md            (referência de endpoints)
└── scripts/
    ├── run_playwright.py       (login automático)
    ├── get_token.py            (trocar código por token)
    ├── test_shopee_api.py      (testar conexão)
    ├── refresh_token.py        (renovar token)
    └── sync_shopee.py          (exemplo de sincronização)
```

## 🔐 Credenciais

### Shop Information
- **Shop ID**: 227695582
- **Partner ID**: 1237032
- **Partner Key**: `shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d`
- **Region**: SG → BR (Brasil)

### Sandbox Account
- **Username**: SANDBOX.b6fb03003426929be0c1
- **Password**: 56194122e737c5cd

### Current Tokens (⚠️ Válidos por ~4 horas)
```
Access Token: 535a586d674844627874525179787554
Refresh Token: 4f59435665486e4b5a51596e46656e4f
Auth Code: 46705950714d6f455775517942704a53
```

## 🚀 Quick Start

### 1. Instalar Dependências
```bash
pip install playwright requests
python -m playwright install
```

### 2. Renovar Token (a cada 4 horas)
```bash
cd api/shopee-integration/scripts
python get_token.py
```

### 3. Testar Conexão
```bash
python test_shopee_api.py
```

### 4. Usar em Workflows GitHub Actions
```yaml
env:
  SHOPEE_ACCESS_TOKEN: ${{ secrets.SHOPEE_ACCESS_TOKEN }}
  SHOPEE_SHOP_ID: ${{ secrets.SHOPEE_SHOP_ID }}
  SHOPEE_PARTNER_ID: ${{ secrets.SHOPEE_TEST_PARTNER_ID }}
  SHOPEE_PARTNER_KEY: ${{ secrets.SHOPEE_TEST_PARTNER_KEY }}
```

## 📋 GitHub Secrets Criados

### Ambos Repositórios
- ✅ SHOPEE_ACCESS_TOKEN
- ✅ SHOPEE_REFRESH_TOKEN
- ✅ SHOPEE_AUTH_CODE
- ✅ SHOPEE_SHOP_ID
- ✅ SHOPEE_TEST_PARTNER_ID
- ✅ SHOPEE_TEST_PARTNER_KEY
- ✅ SHOPEE_SANDBOX_USER
- ✅ SHOPEE_SANDBOX_PASS
- ✅ SHOPEE_TEST_API_KEY
- ✅ SMTP_HOST
- ✅ SMTP_PORT
- ✅ SMTP_USER
- ✅ SMTP_PASS
- ✅ EMAIL_FROM
- ✅ EMAIL_TO
- ✅ + 28 Secrets adicionais

## 🔗 API Base URLs

- **Sandbox**: https://openplatform.sandbox.test-stable.shopee.sg
- **Production**: https://openplatform.shopee.com

## 📚 Documentação

- [API Reference](./API_REFERENCE.md)
- [Credentials](./CREDENTIALS.json)
- [Secrets](./SECRETS.json)

## 🤖 Scripts Disponíveis

### 1. run_playwright.py
Faz login automático no Shopee e captura o código de autorização.

```bash
python scripts/run_playwright.py
```

### 2. get_token.py
Troca o código de autorização por access_token e refresh_token.

```bash
python scripts/get_token.py
```

### 3. test_shopee_api.py
Testa a conexão com os endpoints da API.

```bash
python scripts/test_shopee_api.py
```

### 4. refresh_token.py
Renova o access_token usando o refresh_token.

```bash
python scripts/refresh_token.py
```

## ⚠️ Notas Importantes

1. **Token Expiration**: Tokens expiram a cada 4 horas
2. **Renovação Automática**: Configure em CI/CD para renovar automaticamente
3. **Sign Validation**: Use formato `{PARTNER_ID}{path}{timestamp}` para assinatura
4. **Rate Limits**: Respeite os limites da Shopee
5. **Segurança**: Nunca commit credenciais. Use GitHub Secrets.

## 🔄 Fluxo de Autorização OAuth

```
1. run_playwright.py → Login automático → Código de autorização
   ↓
2. get_token.py → Trocar código → Access Token + Refresh Token
   ↓
3. Armazenar como GitHub Secrets
   ↓
4. Usar em workflows com secrets
   ↓
5. refresh_token.py → Renovar quando expirar
```

## 📞 Contato & Suporte

- Shopee Partner Portal: https://partner.shopeemobile.com/
- Open Platform: https://open.shopee.com/
- Sandbox: https://openplatform.sandbox.test-stable.shopee.sg

---

**Última atualização**: 2026-06-29  
**Status**: ✅ Completo  
**Versão**: 1.0
