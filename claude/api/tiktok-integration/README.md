# 🎵 TikTok Shop API Integration

## 📂 Estrutura

```
api/tiktok-integration/
├── README.md                    (este arquivo)
├── CREDENTIALS.json            (credenciais e tokens)
├── API_REFERENCE.md            (referência de endpoints)
├── SETUP_GUIDE.md              (guia de setup)
└── scripts/
    ├── get_access_token.py     (obter access token)
    ├── refresh_token.py        (renovar token)
    └── test_tiktok_api.py      (testar API)
```

## 🔐 Credenciais

### App Information
- **App Key**: 6kf502maarj2k
- **App Secret**: f0a2a1e58a7le4ca8b5f0f7fdfdb2o0ebee06c
- **Redirect URL**: https://dev.shopvivaliz.com.br
- **Environment**: Sandbox/Production

## 📋 GitHub Secrets

### Criados em ambos repositórios:
- ✅ TIKTOK_APP_KEY
- ✅ TIKTOK_APP_SECRET
- ✅ TIKTOK_REDIRECT_URL

### A Obter (após autorização):
- ⏳ TIKTOK_ACCESS_TOKEN
- ⏳ TIKTOK_REFRESH_TOKEN
- ⏳ TIKTOK_SHOP_ID

## 🚀 Quick Start

### 1. Instalar Dependências
```bash
pip install requests
```

### 2. Obter Access Token
```bash
cd api/tiktok-integration/scripts
python get_access_token.py
```

### 3. Testar Conexão
```bash
python test_tiktok_api.py
```

### 4. Usar em Workflows
```yaml
env:
  TIKTOK_ACCESS_TOKEN: ${{ secrets.TIKTOK_ACCESS_TOKEN }}
  TIKTOK_SHOP_ID: ${{ secrets.TIKTOK_SHOP_ID }}
  TIKTOK_APP_KEY: ${{ secrets.TIKTOK_APP_KEY }}
```

## 🔗 Base URLs

- **Sandbox**: https://open-api.tiktokglobalshop.com (para testes)
- **Production**: https://open-api.tiktokshop.com (live)

## 📚 Documentação Oficial

- https://partner.tiktokshop.com/
- https://developers.tiktok.com/doc/
- API Docs: https://open-api.tiktokshop.com/

## ⚠️ Notas

1. **OAuth Flow**: Use código de autorização para obter tokens
2. **Token Expiration**: Tokens expiram após período (verificar documentação)
3. **Rate Limits**: Respeite limites da API
4. **Segurança**: Nunca commit credenciais. Use GitHub Secrets.

---

**Status**: 🔧 Configuração em andamento  
**Última atualização**: 2026-06-29
