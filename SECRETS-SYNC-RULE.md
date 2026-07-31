# ⚠️ ARQUIVO DEPRECADO

> **Este arquivo foi consolidado em `REGRAS-AGENTES-CENTRALIZADAS.md`**
> 
> **Por favor, use este arquivo em vez:**
> [`REGRAS-AGENTES-CENTRALIZADAS.md` - Seção: Sincronização Obrigatória de Secrets](./REGRAS-AGENTES-CENTRALIZADAS.md#-sincronização-obrigatória-de-secrets-3-ambientes)
> 
> Este arquivo é mantido apenas como referência histórica e será removido em 2026-08-07.

---

# 🔐 REGRA OBRIGATÓRIA: Sincronizar Secrets nos 3 Ambientes (DEPRECADO)

> **CRÍTICO**: Toda alteração de secret DEVE ser sincronizada em TODOS os 3 ambientes simultaneamente.
> **Nunca** deixar um secret desincronizado.

> ⚠️ **CONTEÚDO ABAIXO FOI MOVIDO PARA REGRAS-AGENTES-CENTRALIZADAS.md**

---

## ⚠️ Quando Aplica

**OBRIGATÓRIO sincronizar quando:**
- ✅ Adicionar novo secret
- ✅ Atualizar valor de secret (rotação)
- ✅ Remover secret deprecado
- ✅ Renovar token expirado

**Exemplo de NÃO sincronizar = ERRO:**
```
❌ Atualizar OLIST_REFRESH_TOKEN só em GitHub
❌ Adicionar NOVO_API_KEY só no local
❌ Rotacionar MERCADOPAGO_TOKEN só na VM
```

---

## ✅ Checklist de Sincronização

### Passo 1: EDITAR
```bash
# Local: C:\Users\FRED\site-shopvivaliz\.env
vi .env
```

### Passo 2: COPIAR para VM
```bash
SSH_KEY="C:\Users\FRED\Downloads\ssh-key-2026-07-04.key"
scp -i "$SSH_KEY" .env ubuntu@137.131.156.17:/home/ubuntu/site-shopvivaliz/.env
```

### Passo 3: ATUALIZAR GitHub
```bash
# Via GitHub CLI
gh secret set NOME_SECRET --body "valor"

# OU: Via web
# https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
```

### Passo 4: VALIDAR nos 3 locais
```bash
# Local
grep "NOME_SECRET" C:\Users\FRED\site-shopvivaliz\.env

# VM
ssh -i "$SSH_KEY" ubuntu@137.131.156.17 "grep NOME_SECRET /home/ubuntu/site-shopvivaliz/.env"

# GitHub
gh secret list --repo Vivaliz-site/site-shopvivaliz | grep NOME_SECRET
```

### Passo 5: COMMITAR
```bash
git add .env  # NÃO! .env está em .gitignore
# Em vez disso, commitar o manifesto/auditoria
git commit -m "chore: atualizar NOME_SECRET (sincronizado em 3 ambientes)"
git push origin main
```

---

## 📊 Matriz de Sincronização

| Secret | Local | VM | GitHub | Notas |
|--------|-------|-----|--------|-------|
| **Database** | ✅ | ✅ | ❌ | Nunca em GitHub (risco) |
| **Email/SMTP** | ✅ | ✅ | ✅ | Seguro em GitHub |
| **APIs IA** | ✅ | ✅ | ✅ | Sincronizar sempre |
| **ERP/Commerce** | ✅ | ✅ | ✅ | Sincronizar sempre |
| **Deploy/FTP** | ✅ | ❌ | ✅ | Apenas Local+GitHub |
| **CloudFlare** | ✅ | ❌ | ✅ | Apenas Local+GitHub |

---

## 🛠️ Automatizar com Script

Criar script PowerShell que sincroniza automaticamente:

```powershell
# sync-secrets.ps1
param(
    [string]$SecretName,
    [string]$SecretValue
)

$SSH_KEY = "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key"
$REPO = "C:\Users\FRED\site-shopvivaliz"
$VM = "ubuntu@137.131.156.17"

# 1. Atualizar local
Add-Content "$REPO\.env" "$SecretName=$SecretValue"

# 2. Copiar para VM
scp -i $SSH_KEY "$REPO\.env" "$VM`:/home/ubuntu/site-shopvivaliz/.env"

# 3. GitHub
gh secret set $SecretName --body $SecretValue

# 4. Validar
Write-Host "✅ Secret '$SecretName' sincronizado em 3 ambientes"
```

**Uso:**
```bash
.\sync-secrets.ps1 -SecretName "NOVO_TOKEN" -SecretValue "valor"
```

---

## ⚠️ O que Quebra se Não Sincronizar

| Cenário | Impacto | Severidade |
|---------|---------|-----------|
| **Atualizar só Local** | VM usa valor antigo → Erro 401 em produção | 🔴 CRÍTICO |
| **Atualizar só VM** | GitHub CI falha → Deploy quebrado | 🔴 CRÍTICO |
| **Atualizar só GitHub** | Local testa com valor errado | 🟡 MÉDIO |
| **Desatualizar 2/3** | Inconsistência impossível debugar | 🔴 CRÍTICO |

---

## 📋 Template de Commit

Quando atualizar secrets, commitar assim:

```
chore: sincronizar secrets nos 3 ambientes

Atualizados:
- OLIST_REFRESH_TOKEN (rotação programada)
- MERCADOPAGO_WEBHOOK_SECRET (atualizado)

Sincronizados em:
✅ Local (.env)
✅ VM Oracle (.env)
✅ GitHub Secrets

Validados via:
- grep local
- ssh remote
- gh secret list
```

---

## 🔄 Rotação Programada

**Secrets que expiram regularmente:**

| Secret | Validade | Próxima Rotação |
|--------|----------|-----------------|
| OLIST_REFRESH_TOKEN | 90 dias | 2026-10-17 |
| TINY_REFRESH_TOKEN | 90 dias | 2026-10-17 |
| GOOGLE_OAUTH_* | Indefinido | Verificar anualmente |
| MELHORENVIO_* | Indefinido | Verificar anualmente |

**Calendário:**
```bash
# Cron job na VM (rotaciona automaticamente)
0 0 17 * * /home/ubuntu/rotate-secrets.sh
```

---

## ✅ Verificação Semanal

Toda **segunda-feira 09:00**, rodar:

```bash
#!/bin/bash
# check-secrets-sync.sh

LOCAL_COUNT=$(grep -c "^[A-Z_]" ~/.env 2>/dev/null || echo 0)
VM_COUNT=$(ssh -i key.pem ubuntu@137.131.156.17 "grep -c '^[A-Z_]' .env" 2>/dev/null || echo 0)
GH_COUNT=$(gh secret list --repo Vivaliz-site/site-shopvivaliz | wc -l)

if [ "$LOCAL_COUNT" != "$VM_COUNT" ]; then
  echo "⚠️ DESINCRONIZADO: Local=$LOCAL_COUNT, VM=$VM_COUNT"
  exit 1
fi

echo "✅ Secrets sincronizados: $LOCAL_COUNT"
```

---

## 🚨 SOS: Descobriu Desincronização?

**Ação imediata:**

```bash
# 1. Verificar qual está correto
gh secret list  # GitHub é fonte de verdade
ssh -i key.pem ubuntu@137.131.156.17 "grep NOME .env"  # Compare

# 2. Copiar do correto para os outros
# Se GitHub tá certo:
gh secret get NOME > valor.txt
scp valor.txt ...

# 3. Commitar estado correto
git commit -m "fix: sincronizar secrets desincronizados (SOURCE: GitHub)"
```

---

**NUNCA deixar secrets desincronizados por mais de 5 minutos.**
