# 🔐 Setup de Secrets Centralizados - ShopVivaliz

## ⚡ Quick Start (5 Minutos)

### Passo 1: Preparar o Ambiente

```bash
# Verificar que config/secrets.py existe
ls -la config/

# Deve mostrar:
# config/__init__.py
# config/secrets.py
```

### Passo 2: Copiar Secrets Reais

```bash
# Se você já tem .env.local com secrets reais:
# Nada a fazer! Arquivo já será carregado.

# Se você NÃO tem .env.local ainda:
# 1. Copie .env.example para .env.local
# 2. Preencha com valores reais

# Importante: .env.local NUNCA deve ser commitado!
git status | grep .env.local  # Deve estar em .gitignore
```

### Passo 3: Validar Setup

```bash
# Teste se tudo está configurado
python3 scripts/validar_secrets.py

# Saída esperada:
# ✅ SUCESSO - Todos os secrets obrigatórios estão configurados!
# ✓ Sistema pronto para uso!
```

### Passo 4: Escanear Scripts Atuais

```bash
# Ver quais scripts precisam migração
python3 scripts/migrar_secrets.py scan

# Saída esperada mostrará:
# ✅ Já migrados: 0
# ⚠️  Precisam migração: X scripts
# ⭕ Sem secrets: Y scripts
```

### Passo 5: Migrar Scripts (Simulação)

```bash
# Simular migração (sem fazer mudanças)
python3 scripts/migrar_secrets.py dry-run

# Revisar mudanças propostas
# Se OK, executar migração real:
python3 scripts/migrar_secrets.py migrate
```

---

## 📁 Estrutura de Arquivos

```
site-shopvivaliz/
├── config/
│   ├── __init__.py              ← Exports dos secrets
│   └── secrets.py               ← Módulo centralizado (NOVO!)
│
├── scripts/
│   ├── migrar_secrets.py        ← Automação de migração (NOVO!)
│   ├── validar_secrets.py       ← Validador (NOVO!)
│   ├── sync-shopee.py           ← (será migrado)
│   ├── send_report.py           ← (será migrado)
│   └── ... outros scripts
│
├── .env.example                 ← Template (já existe)
├── .env.local                   ← Secrets REAIS (já existe, nunca commitar)
├── .gitignore                   ← Deve conter .env.local
│
└── SETUP_SECRETS_README.md      ← Este arquivo!
```

---

## 🔧 Como Usar em um Script

### ❌ Forma Antiga (NÃO USE)

```python
import os
from dotenv import load_dotenv

load_dotenv()
API_KEY = os.getenv("ANTHROPIC_API_KEY")
```

### ✅ Forma Nova (USE)

```python
from config.secrets import ANTHROPIC_API_KEY

# É isso! Variável já está carregada e validada
print(f"API Key: {ANTHROPIC_API_KEY[:10]}...")
```

---

## 🛠️ Troubleshooting

### Problema 1: ModuleNotFoundError

```
Error: No module named 'config'
```

**Solução:**
```python
# Se seu script está em um subdiretório:
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent.parent))

from config.secrets import ANTHROPIC_API_KEY
```

### Problema 2: Secrets Vazios

```
❌ ERRO - Secrets obrigatórios faltando
```

**Solução:**
1. Verificar se `.env.local` existe
2. Se não existe, copiar `.env.example` para `.env.local`
3. Preencher valores reais em `.env.local`
4. Rodar validador novamente

### Problema 3: .env.local Commitado por Acidente

```bash
# Se você commitou .env.local acidentalmente:
git rm --cached .env.local
git commit -m "Remove .env.local from tracking"

# Adicionar ao .gitignore se não estiver
echo ".env.local" >> .gitignore
git add .gitignore
git commit -m "Add .env.local to .gitignore"
```

---

## 📊 Comparação: Antes vs Depois

| Aspecto | Antes | Depois |
|---------|-------|--------|
| **Número de arquivos de config** | 6+ (constants, .env, .env.local, etc) | 1 (config/secrets.py) |
| **Busca de secrets** | `os.getenv()` em cada script | `from config.secrets import` |
| **Validação** | Manual (cada script valida) | Automática (centralizada) |
| **Segurança em logs** | Pode expor secrets | Sempre mascarados |
| **IDE autocomplete** | ❌ Não funciona | ✅ Funciona |
| **Manutenção** | ❌ Difícil (duplicação) | ✅ Fácil (um lugar) |

---

## 🎯 Próximos Passos

1. ✅ Validar que tudo funciona: `python3 scripts/validar_secrets.py`
2. ✅ Migrar scripts críticos primeiro (FTP, Email, etc)
3. ✅ Testar cada script migrado
4. ✅ Commitar mudanças: `git add config/ scripts/`
5. ✅ Push para `main` (dispara deploy automático)

---

## 📚 Documentação Completa

- [MIGRACAO_SECRETS.md](MIGRACAO_SECRETS.md) - Guia detalhado de migração
- [config/secrets.py](config/secrets.py) - Código do módulo centralizado
- [.env.example](.env.example) - Template de variáveis

---

## ✅ Checklist

- [ ] config/secrets.py existe
- [ ] config/__init__.py existe
- [ ] .env.local preenchido com valores reais
- [ ] `python3 scripts/validar_secrets.py` passa
- [ ] Scripts principais migrados
- [ ] Testes passam
- [ ] Código commitado

---

**Status: ✅ Pronto para Implementação**

Tempo estimado: 5-15 minutos ⚡
