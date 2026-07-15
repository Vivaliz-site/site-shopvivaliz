# 🔐 SINCRONIZAR NOMES DOS SECRETS

**Data:** 29/06/2026  
**Status:** ✅ Script de verificação criado

---

## 📝 COMO VERIFICAR NOMES DOS SECRETS

### Passo 1: Acessar GitHub Secrets

```
https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
```

Você verá uma lista como:

```
NAME                      UPDATED_AT
─────────────────────────────────────
OPENAI_API_KEY           2026-06-29
FTP_HOST                 2026-06-29  ← PODE SER FTP_HOST em vez de FTP_SERVER
SHOPEE_ID                2026-06-29  ← PODE SER SHOPEE_ID em vez de SHOPEE_PARTNER_ID
```

### Passo 2: Anotar Nomes Exatos

**Copie os nomes EXATOS do GitHub Secrets**, não os nomes esperados!

Exemplo de nomes que podem estar diferentes:

```
ESPERADO              →  REAL (no GitHub)
────────────────────────────────────────
FTP_SERVER            →  FTP_HOST
FTP_USERNAME          →  FTP_USER
FTP_PASSWORD          →  FTP_PASS
SHOPEE_PARTNER_ID     →  SHOPEE_ID
SHOPEE_PARTNER_KEY    →  SHOPEE_KEY
TIKTOK_CLIENT_ID      →  TIKTOK_ID
TIKTOK_CLIENT_SECRET  →  TIKTOK_SECRET
OPENAI_API_KEY        →  OPENAI_KEY
```

### Passo 3: Usar Script de Verificação

Se os secrets estiverem como variáveis de ambiente locais, execute:

```bash
python verificar_secrets_nomes.py
```

O script dirá quais estão configurados e seus nomes exatos.

---

## 🔧 SE OS NOMES FOREM DIFERENTES

### Opção 1: Corrigir o Código (MELHOR)

Se os secrets no GitHub têm nomes diferentes, edite os arquivos para usar os nomes corretos:

#### Arquivo: `scripts/ia/image_generator.py`

**JÁ CORRIGIDO** - Suporta múltiplos nomes:

```python
self.api_key = (
    os.getenv('OPENAI_API_KEY') or
    os.getenv('OPENAI_API_KEY_SK') or
    os.getenv('OPENAI_KEY') or
    os.getenv('OPENAI_SECRET') or
    ''
)
```

#### Arquivo: `scripts/upload_images.py`

**JÁ CORRIGIDO** - Suporta múltiplos nomes:

```python
host = get_env_variable('FTP_HOST', ['FTP_SERVER'])
user = get_env_variable('FTP_USER', ['FTP_USERNAME'])
password = get_env_variable('FTP_PASS', ['FTP_PASSWORD'])
```

#### Arquivo: `scripts/integrations/shopee_api.py`

Se existir e usar secrets, adicione suporte a aliases:

```python
SHOPEE_ID = os.getenv('SHOPEE_PARTNER_ID') or os.getenv('SHOPEE_ID')
SHOPEE_KEY = os.getenv('SHOPEE_PARTNER_KEY') or os.getenv('SHOPEE_KEY')
```

#### Arquivo: `scripts/integrations/tiktok_api.py`

Se existir e usar secrets, adicione suporte a aliases:

```python
TIKTOK_ID = os.getenv('TIKTOK_CLIENT_ID') or os.getenv('TIKTOK_ID')
TIKTOK_SECRET = os.getenv('TIKTOK_CLIENT_SECRET') or os.getenv('TIKTOK_SECRET')
```

### Opção 2: Criar Arquivo de Mapeamento

Crie: `scripts/config/secrets_mapping.py`

```python
import os

# Mapa de nomes possíveis para cada secret
SECRETS = {
    'OPENAI_API_KEY': os.getenv('OPENAI_API_KEY') or os.getenv('OPENAI_KEY'),
    'FTP_SERVER': os.getenv('FTP_SERVER') or os.getenv('FTP_HOST'),
    'FTP_USERNAME': os.getenv('FTP_USERNAME') or os.getenv('FTP_USER'),
    'FTP_PASSWORD': os.getenv('FTP_PASSWORD') or os.getenv('FTP_PASS'),
    'SHOPEE_ID': os.getenv('SHOPEE_PARTNER_ID') or os.getenv('SHOPEE_ID'),
    'SHOPEE_KEY': os.getenv('SHOPEE_PARTNER_KEY') or os.getenv('SHOPEE_KEY'),
    'TIKTOK_ID': os.getenv('TIKTOK_CLIENT_ID') or os.getenv('TIKTOK_ID'),
    'TIKTOK_SECRET': os.getenv('TIKTOK_CLIENT_SECRET') or os.getenv('TIKTOK_SECRET'),
}

def get_secret(name):
    """Retorna valor do secret pelo nome canônico"""
    return SECRETS.get(name)
```

Depois use em todos os scripts:

```python
from scripts.config.secrets_mapping import get_secret

openai_key = get_secret('OPENAI_API_KEY')
ftp_server = get_secret('FTP_SERVER')
```

### Opção 3: Renomear Secrets no GitHub

1. Acesse: `https://github.com/.../settings/secrets/actions`
2. Delete secret com nome errado
3. Crie novo com nome correto
4. Faça novo push para workflow usar

**NÃO RECOMENDADO** - Mais trabalho

---

## ✅ CHECKLIST DE SINCRONIZAÇÃO

### 1. Verificar Nomes no GitHub
- [ ] Acesse GitHub Secrets
- [ ] Liste todos os secrets configurados
- [ ] Anote os nomes EXATOS

### 2. Comparar com Esperado
- [ ] OPENAI_API_KEY (ou OPENAI_KEY?)
- [ ] FTP_SERVER (ou FTP_HOST?)
- [ ] FTP_USERNAME (ou FTP_USER?)
- [ ] FTP_PASSWORD (ou FTP_PASS?)
- [ ] SHOPEE_PARTNER_ID (ou SHOPEE_ID?)
- [ ] SHOPEE_PARTNER_KEY (ou SHOPEE_KEY?)
- [ ] TIKTOK_CLIENT_ID (ou TIKTOK_ID?)
- [ ] TIKTOK_CLIENT_SECRET (ou TIKTOK_SECRET?)
- [ ] EMAIL_FROM
- [ ] EMAIL_TO
- [ ] EMAIL_USER
- [ ] EMAIL_PASSWORD
- [ ] EMAIL_SMTP_HOST
- [ ] EMAIL_SMTP_PORT

### 3. Se Nomes Diferentes
- [ ] Opção 1: Adicionar suporte a aliases no código
- [ ] Opção 2: Criar arquivo de mapeamento
- [ ] Opção 3: Renomear no GitHub (NÃO RECOMENDADO)

### 4. Validar
- [ ] Executar verificador novamente
- [ ] Todos encontrados? ✅
- [ ] Fazer git push

---

## 📊 EXEMPLO: NOMES DIFERENTES ENCONTRADOS

Se os nomes são:

```
NO GITHUB              →  NO CÓDIGO (esperado)
──────────────────────────────────────────────
FTP_HOST              →  FTP_SERVER
FTP_USER              →  FTP_USERNAME
FTP_PASS              →  FTP_PASSWORD
SHOPEE_ID             →  SHOPEE_PARTNER_ID
OPENAI_KEY            →  OPENAI_API_KEY
```

### Solução Rápida (Opção 1):

Arquivo: `scripts/upload_images.py`

```python
# ANTES:
host = get_env_variable('FTP_HOST', ['FTP_SERVER'])
user = get_env_variable('FTP_USER', ['FTP_USERNAME'])
password = get_env_variable('FTP_PASS', ['FTP_PASSWORD'])

# JÁ ESTÁ CERTO! ✅
# Ele tenta FTP_HOST primeiro, depois FTP_SERVER
```

Arquivo: `scripts/ia/image_generator.py`

```python
# ANTES:
self.api_key = os.getenv('OPENAI_API_KEY', '')

# DEPOIS:
self.api_key = (
    os.getenv('OPENAI_API_KEY') or
    os.getenv('OPENAI_KEY') or
    ''
)
```

---

## 🎯 RESUMO

**Se os secrets no GitHub têm nomes IGUAIS aos esperados:**
- ✅ Nada precisa ser alterado
- ✅ Sistema funciona direto
- ✅ Fazer git push

**Se os secrets têm nomes DIFERENTES:**
- ⚠️  Adicionar suporte a aliases no código
- ⚠️  Fazer git push
- ⚠️  Testar no GitHub Actions

---

## 🚀 PRÓXIMAS AÇÕES

1. **Imediato:**
   ```
   Acessar GitHub Secrets
   Anotar nomes EXATOS
   Executar verificador (se variáveis de ambiente)
   ```

2. **Se Nomes Diferentes:**
   ```
   Editar scripts com aliases
   git add scripts/
   git commit
   git push
   ```

3. **Se Nomes Iguais:**
   ```
   git push origin main
   GitHub Actions executará
   Sistema gerará imagens
   ```

---

**Status:** ✅ Script criado, pronto para sincronizar! 🔐
