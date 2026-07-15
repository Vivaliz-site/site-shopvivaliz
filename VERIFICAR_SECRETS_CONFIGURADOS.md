# 🔐 VERIFICAR SECRETS JÁ CONFIGURADOS

**Data:** 29/06/2026  
**Status:** ✅ Secrets podem estar configurados com nomes diferentes

---

## 📋 COMO VERIFICAR SECRETS CONFIGURADOS

### Método 1: GitHub Web Interface (RECOMENDADO)

1. Acesse:
   ```
   https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
   ```

2. Você verá uma lista de secrets configurados:
   ```
   NAME                    UPDATED_AT
   ─────────────────────────────────
   FTP_PASSWORD            2026-06-29
   FTP_SERVER              2026-06-29
   FTP_USERNAME            2026-06-29
   EMAIL_PASSWORD          2026-06-29
   OPENAI_API_KEY          2026-06-29
   SHOPEE_PARTNER_ID       2026-06-29
   SHOPEE_PARTNER_KEY      2026-06-29
   TIKTOK_CLIENT_ID        2026-06-29
   TIKTOK_CLIENT_SECRET    2026-06-29
   # ... mais secrets
   ```

3. **ANOTE OS NOMES EXATOS** - podem ser diferentes!

---

## 🔍 NOMES QUE PODEM ESTAR DIFERENTES

### FTP Secrets (variações possíveis):

```
✅ Esperado:  FTP_SERVER
❓ Possível:  FTP_HOST, FTP_ADDRESS, FTPHOST

✅ Esperado:  FTP_USERNAME
❓ Possível:  FTP_USER, FTP_ACCOUNT

✅ Esperado:  FTP_PASSWORD
❓ Possível:  FTP_PASS, FTP_PWD

✅ Esperado:  FTP_PORT
❓ Possível:  FTPPORT, FTP_PORT_NUMBER
```

### Shopee Secrets (variações possíveis):

```
✅ Esperado:  SHOPEE_PARTNER_ID
❓ Possível:  SHOPEE_ID, SHOPEE_SHOP_ID

✅ Esperado:  SHOPEE_PARTNER_KEY
❓ Possível:  SHOPEE_KEY, SHOPEE_PARTNER_SECRET, SHOPEE_SECRET
```

### TikTok Secrets (variações possíveis):

```
✅ Esperado:  TIKTOK_CLIENT_ID
❓ Possível:  TIKTOK_ID, TIKTOK_APP_ID

✅ Esperado:  TIKTOK_CLIENT_SECRET
❓ Possível:  TIKTOK_SECRET, TIKTOK_APP_SECRET
```

### Email Secrets (variações possíveis):

```
✅ Esperado:  EMAIL_FROM
❓ Possível:  SENDER_EMAIL, EMAIL_SENDER

✅ Esperado:  EMAIL_USER
❓ Possível:  SMTP_USER, EMAIL_ACCOUNT

✅ Esperado:  EMAIL_PASSWORD
❓ Possível:  EMAIL_PASS, SMTP_PASSWORD
```

---

## 📝 TEMPLATE: REGISTRAR NOMES REAIS

Quando verificar no GitHub, **COPIE OS NOMES EXATOS** aqui:

```markdown
### SECRETS CONFIGURADOS (NOMES REAIS):

FTP:
  [ ] Nome real: _________________ (esperado: FTP_SERVER)
  [ ] Nome real: _________________ (esperado: FTP_USERNAME)
  [ ] Nome real: _________________ (esperado: FTP_PASSWORD)
  [ ] Nome real: _________________ (esperado: FTP_PORT)

SHOPEE:
  [ ] Nome real: _________________ (esperado: SHOPEE_PARTNER_ID)
  [ ] Nome real: _________________ (esperado: SHOPEE_PARTNER_KEY)

TIKTOK:
  [ ] Nome real: _________________ (esperado: TIKTOK_CLIENT_ID)
  [ ] Nome real: _________________ (esperado: TIKTOK_CLIENT_SECRET)

IA:
  [ ] Nome real: _________________ (esperado: OPENAI_API_KEY)
  [ ] Nome real: _________________ (esperado: ANTHROPIC_API_KEY)

EMAIL:
  [ ] Nome real: _________________ (esperado: EMAIL_FROM)
  [ ] Nome real: _________________ (esperado: EMAIL_TO)
  [ ] Nome real: _________________ (esperado: EMAIL_USER)
  [ ] Nome real: _________________ (esperado: EMAIL_PASSWORD)
  [ ] Nome real: _________________ (esperado: EMAIL_SMTP_HOST)
  [ ] Nome real: _________________ (esperado: EMAIL_SMTP_PORT)
```

---

## 🔧 CORRIGIR NOMES DOS SECRETS

Se os nomes forem diferentes, **3 opções**:

### Opção 1: Atualizar Código (MELHOR)

Arquivo: `scripts/integrations/ftp_uploader.py`

```python
# ANTES:
self.ftp_host = os.getenv('FTP_SERVER', '')

# DEPOIS (se secret é FTP_HOST):
self.ftp_host = os.getenv('FTP_HOST', '')
```

**Fazer isso em TODOS os arquivos que usam secrets:**
- `scripts/integrations/ftp_uploader.py`
- `scripts/integrations/shopee_api.py`
- `scripts/integrations/tiktok_api.py`
- `scripts/integrations/marketplace_validator.py`
- Outros scripts que usam os secrets

### Opção 2: Adicionar Aliases

Arquivo: `scripts/utils/config.py` (criar se não existir)

```python
import os

def get_secret(name, alternatives=None):
    """Busca secret com suporte a nomes alternativos"""
    value = os.getenv(name)
    if value:
        return value
    
    # Tentar nomes alternativos
    if alternatives:
        for alt_name in alternatives:
            value = os.getenv(alt_name)
            if value:
                return value
    
    return None

# Usar em vez de os.getenv:
FTP_SERVER = get_secret('FTP_SERVER', ['FTP_HOST', 'FTP_ADDRESS'])
SHOPEE_ID = get_secret('SHOPEE_PARTNER_ID', ['SHOPEE_ID', 'SHOPEE_SHOP_ID'])
```

### Opção 3: Renomear Secrets no GitHub

1. Vá em: `https://github.com/.../settings/secrets/actions`
2. Delete o secret com nome errado
3. Crie novo com nome correto
4. Precisa fazer push novamente para workflow usar

---

## ✅ CHECKLIST: VERIFICAR SECRETS

- [ ] Acessei: `https://github.com/.../settings/secrets/actions`
- [ ] Vi lista de secrets configurados
- [ ] Anotei todos os nomes exatos
- [ ] Comparei com nomes esperados
- [ ] Se diferentes: escolhi Opção 1, 2 ou 3
- [ ] Atualizei o código se necessário
- [ ] Fiz git push
- [ ] Workflow executou com sucesso

---

## 🚀 DEPOIS DE VERIFICAR/CORRIGIR

### Se nomes estão CORRETOS:
```bash
git push origin main
# Sistema começará automaticamente
```

### Se nomes estão DIFERENTES (Opção 1 - Código):
```bash
# Edite scripts
# Altere os.getenv() para usar nomes corretos
git add scripts/
git commit -m "fix: Ajustar nomes dos secrets"
git push origin main
```

### Se nomes estão DIFERENTES (Opção 2 - Aliases):
```bash
# Crie utils/config.py com aliases
# Use get_secret() em todos os scripts
git add scripts/
git commit -m "feat: Suporte a aliases para secrets"
git push origin main
```

---

## 📊 EXEMPLO: SECRETS CONFIGURADOS

Se encontrar isso no GitHub:

```
NAME                    UPDATED_AT
───────────────────────────────────
FTPHOST                 2026-06-29  ← FTP_SERVER
FTP_USER                2026-06-29  ← FTP_USERNAME
FTP_PWD                 2026-06-29  ← FTP_PASSWORD
SHOPEE_ID               2026-06-29  ← SHOPEE_PARTNER_ID
SHOPEE_SECRET           2026-06-29  ← SHOPEE_PARTNER_KEY
OPENAI_KEY              2026-06-29  ← OPENAI_API_KEY
```

**Então precisa atualizar o código:**

```python
# scripts/integrations/ftp_uploader.py
self.ftp_host = os.getenv('FTPHOST')      # ← Corrigido
self.ftp_user = os.getenv('FTP_USER')     # ← Corrigido
self.ftp_pass = os.getenv('FTP_PWD')      # ← Corrigido

# scripts/integrations/shopee_api.py
SHOPEE_ID = os.getenv('SHOPEE_ID')        # ← Corrigido
SHOPEE_KEY = os.getenv('SHOPEE_SECRET')   # ← Corrigido

# scripts/integrations/...
OPENAI_API_KEY = os.getenv('OPENAI_KEY')  # ← Corrigido
```

---

## 🎯 PRÓXIMO PASSO

1. **VERIFICAR**: Acesse a página de secrets no GitHub
2. **ANOTAR**: Nomes exatos de cada secret
3. **COMPARAR**: Com nomes esperados
4. **CORRIGIR**: Se diferentes (Opção 1, 2 ou 3)
5. **PUSH**: `git push origin main`
6. **MONITORAR**: GitHub Actions executará

Depois sistema funcionará 100%! 🚀
