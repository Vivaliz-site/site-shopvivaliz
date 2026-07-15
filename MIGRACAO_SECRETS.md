# Migração para Secrets Centralizados

## 📋 Resumo

Todos os scripts devem **parar de buscar secrets diretamente** do `os.getenv()` e começar a **importar do módulo centralizado** `config.secrets`.

| Aspecto | Antes | Depois |
|---------|-------|--------|
| Onde buscam secrets | Espalhado (cada script busca) | Centralizado em `config/secrets.py` |
| Importação | `import os` | `from config.secrets import SHOPEE_API_KEY` |
| Validação | Nenhuma | Automática (fail-fast) |
| Logs | Pode expor secrets | Sempre mascarados |
| Manutenção | Difícil (múltiplas buscas) | Fácil (um único lugar) |

---

## 🔧 Como Migrar seus Scripts

### Passo 1: Entender os 3 padrões

#### ❌ **ANTES** (Forma Errada - Atual)

```python
#!/usr/bin/env python3
import os
from dotenv import load_dotenv

load_dotenv()

# Buscando secrets diretamente (sem validação, sem padrão)
SHOPEE_API_KEY = os.getenv("SHOPEE_API_KEY", "")
SHOPEE_PARTNER_ID = os.getenv("SHOPEE_PARTNER_ID", "")
ANTHROPIC_KEY = os.getenv("ANTHROPIC_API_KEY", "")
FTP_SERVER = os.getenv("FTP_SERVER", "")
FTP_PASSWORD = os.getenv("FTP_PASSWORD", "")

if not SHOPEE_API_KEY or not ANTHROPIC_KEY:
    print("ERROR: Missing secrets!")
    exit(1)

# Usando secrets
print(f"Conectando ao Shopee: {SHOPEE_API_KEY[:4]}...")
```

**Problemas:**
- ❌ Cada script carrega `.env` de novo (ineficiente)
- ❌ Sem validação centralizada (erros aparecem tarde)
- ❌ Pode expor secrets em logs se não tomar cuidado
- ❌ Duplicação de código

---

#### ✅ **DEPOIS** (Forma Profissional - Nova)

```python
#!/usr/bin/env python3
# Agora: Importa TUDO centralizado
from config.secrets import (
    SHOPEE_API_KEY,
    SHOPEE_PARTNER_ID,
    ANTHROPIC_API_KEY,
    FTP_SERVER,
    FTP_PASSWORD,
    validate_secrets,
    mask_secret,
)

# Opcional: Validar no startup (recomendado)
success, errors = validate_secrets()
if not success:
    for error in errors:
        print(f"❌ {error}")
    exit(1)

# Usando secrets (valores garantidos seguros)
print(f"Conectando ao Shopee: {mask_secret(SHOPEE_API_KEY)}...")
```

**Benefícios:**
- ✅ Um único lugar para buscar (centralizado)
- ✅ Validação automática
- ✅ Secrets sempre mascarados em logs
- ✅ IDE autocomplete funciona
- ✅ Fácil de manter

---

### Passo 2: Padrões de Migração por Tipo de Script

#### Pattern 1: Script Simples (Busca um ou dois secrets)

```python
# ❌ ANTES
import os
SHOPEE_API_KEY = os.getenv("SHOPEE_API_KEY")
print(SHOPEE_API_KEY)

# ✅ DEPOIS
from config.secrets import SHOPEE_API_KEY
print(SHOPEE_API_KEY)
```

---

#### Pattern 2: Script com Classe (Busca múltiplos secrets)

```python
# ❌ ANTES
import os
from dotenv import load_dotenv

class ShopeeClient:
    def __init__(self):
        load_dotenv()
        self.api_key = os.getenv("SHOPEE_API_KEY")
        self.partner_id = os.getenv("SHOPEE_PARTNER_ID")
        self.shop_id = os.getenv("SHOPEE_SHOP_ID")

    def connect(self):
        print(f"API Key: {self.api_key[:4]}")

# ✅ DEPOIS
from config.secrets import (
    SHOPEE_API_KEY,
    SHOPEE_PARTNER_ID,
    SHOPEE_SHOP_ID,
)

class ShopeeClient:
    def __init__(self):
        self.api_key = SHOPEE_API_KEY
        self.partner_id = SHOPEE_PARTNER_ID
        self.shop_id = SHOPEE_SHOP_ID

    def connect(self):
        from config.secrets import mask_secret
        print(f"API Key: {mask_secret(self.api_key)}")
```

---

#### Pattern 3: Script com Email/FTP (Busca configuração complexa)

```python
# ❌ ANTES
import os
import smtplib
from dotenv import load_dotenv

load_dotenv()

SMTP_HOST = os.getenv("SMTP_HOST") or os.getenv("MAIL_HOST")
SMTP_PORT = int(os.getenv("SMTP_PORT", "465"))
SMTP_USER = os.getenv("SMTP_USER") or os.getenv("MAIL_USER")
SMTP_PASS = os.getenv("SMTP_PASS") or os.getenv("MAIL_PASS")

server = smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT)
server.login(SMTP_USER, SMTP_PASS)

# ✅ DEPOIS
import smtplib
from config.secrets import (
    SMTP_HOST,
    SMTP_PORT,
    SMTP_USER,
    SMTP_PASS,
    validate_secrets,
)

# Validação automática
success, errors = validate_secrets()
if not success:
    raise RuntimeError(f"Secrets inválidos: {errors}")

server = smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT)
server.login(SMTP_USER, SMTP_PASS)
```

---

### Passo 3: Exemplo Completo de Migração

#### Script: `scripts/sync-shopee.py`

**ANTES (❌ Desorganizado):**

```python
#!/usr/bin/env python3
"""Sincroniza produtos com Shopee."""
import os
import requests
from dotenv import load_dotenv
import logging

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

# Buscando secrets desorganizado
SHOPEE_PARTNER_ID = os.getenv("SHOPEE_PARTNER_ID")
SHOPEE_PARTNER_KEY = os.getenv("SHOPEE_PARTNER_KEY")
SHOPEE_SHOP_ID = os.getenv("SHOPEE_SHOP_ID")
SHOPEE_ACCESS_TOKEN = os.getenv("SHOPEE_ACCESS_TOKEN")
ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY")

# Validação improvisada
if not all([SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY, SHOPEE_ACCESS_TOKEN]):
    logger.error("Missing Shopee credentials!")
    exit(1)

class ShopeeSync:
    def __init__(self):
        self.partner_id = SHOPEE_PARTNER_ID
        self.api_key = SHOPEE_PARTNER_KEY
        self.shop_id = SHOPEE_SHOP_ID
        self.access_token = SHOPEE_ACCESS_TOKEN

    def sync(self):
        logger.info(f"Sincronizando shop {self.shop_id}")
        # ... resto do código

if __name__ == "__main__":
    sync = ShopeeSync()
    sync.sync()
```

**DEPOIS (✅ Profissional):**

```python
#!/usr/bin/env python3
"""Sincroniza produtos com Shopee."""
import logging
import requests

from config.secrets import (
    SHOPEE_PARTNER_ID,
    SHOPEE_PARTNER_KEY,
    SHOPEE_SHOP_ID,
    SHOPEE_ACCESS_TOKEN,
    ANTHROPIC_API_KEY,
    validate_secrets,
    mask_secret,
)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Validação automática centralizada
success, errors = validate_secrets()
if not success:
    for error in errors:
        logger.error(error)
    exit(1)

class ShopeeSync:
    def __init__(self):
        self.partner_id = SHOPEE_PARTNER_ID
        self.api_key = SHOPEE_PARTNER_KEY
        self.shop_id = SHOPEE_SHOP_ID
        self.access_token = SHOPEE_ACCESS_TOKEN

    def sync(self):
        logger.info(f"Sincronizando shop {self.shop_id}")
        logger.debug(f"API Key: {mask_secret(self.api_key)}")
        # ... resto do código

if __name__ == "__main__":
    sync = ShopeeSync()
    sync.sync()
```

**Alterações:**
- ✅ Remove `load_dotenv()` (centralizado)
- ✅ Importa do `config.secrets` (uma fonte)
- ✅ Usa `validate_secrets()` automático
- ✅ Logs mascarados com `mask_secret()`

---

## 📝 Checklist de Migração

Para cada script, verificar:

- [ ] Remove `import os` e `load_dotenv()`
- [ ] Adiciona `from config.secrets import ...`
- [ ] Importa apenas secrets que usa
- [ ] Usa `validate_secrets()` se necessário
- [ ] Substitui `os.getenv()` por importação direta
- [ ] Testa o script
- [ ] Commita com mensagem clara

---

## 🚀 Ordem de Migração Recomendada

1. **Scripts críticos** (deploy, email, ftp)
   - `scripts/autonomous-executor.py`
   - `scripts/send_report.py`
   - `scripts/update_dashboard.py`

2. **Scripts de integrações** (marketplace)
   - `scripts/sync-shopee.py`
   - `scripts/sync-olist.py`
   - `scripts/exchange-tiktok-code.py`

3. **Scripts auxiliares** (testes, utilities)
   - `scripts/get_token.py`
   - `scripts/verify_secrets.py`

---

## 🧪 Testando a Migração

```bash
# 1. Testar importação
python3 -c "from config.secrets import ANTHROPIC_API_KEY; print('✓ Import OK')"

# 2. Testar validação
python3 -c "from config.secrets import validate_secrets; s,e = validate_secrets(); print('✓ Validation OK' if s else e)"

# 3. Testar script migrado
python3 scripts/sync-shopee.py --dry-run

# 4. Ver logs mascarados
python3 scripts/sync-shopee.py 2>&1 | grep "API"
```

---

## 🆘 Troubleshooting

### Erro: `ModuleNotFoundError: No module named 'config'`

**Causa:** Script está em subdiretório e não consegue importar.

**Solução:**
```python
from sys import path
from pathlib import Path
path.insert(0, str(Path(__file__).parent.parent))  # Sobe um nível

from config.secrets import ANTHROPIC_API_KEY
```

---

### Erro: `REQUIRED_SECRETS validation failed`

**Causa:** Secrets obrigatórios estão faltando no `.env.local`.

**Solução:**
```bash
# Ver quais secrets estão faltando
python3 -c "from config.secrets import validate_secrets, get_all_secrets; \
s, e = validate_secrets(); \
[print(x) for x in e]; \
print('\nSecrets presentes:'); \
import json; print(json.dumps(get_all_secrets(), indent=2))"
```

---

## 📚 Referências

- [config/secrets.py](config/secrets.py) - Arquivo centralizado
- [config/__init__.py](config/__init__.py) - Exports
- [.env.example](.env.example) - Template de variáveis

---

**Status:** ✅ Pronto para migração
