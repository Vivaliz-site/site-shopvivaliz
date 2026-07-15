# 🔐 GUIA COMPLETO - CONFIGURAR GITHUB SECRETS

**Versão:** 2.0  
**Data:** 29/06/2026  
**Repositório:** https://github.com/fredmourao-ai/site-shopvivaliz

---

## 📋 VISÃO GERAL

Para que o pipeline automático funcione completamente, você precisa configurar as **credenciais nos GitHub Secrets**.

Os secrets são variáveis seguras que:
- ✅ Ficam encriptadas no GitHub
- ✅ São injetadas automaticamente no pipeline
- ✅ Nunca aparecem nos logs
- ✅ Nunca são salvos localmente

---

## 🎯 SECRETS NECESSÁRIOS

| Categoria | Secret | Descrição | Prioridade |
|-----------|--------|-----------|-----------|
| 🤖 IA | `OPENAI_API_KEY` | Gerar imagens com IA | ⭐⭐⭐ ALTA |
| 🛍️ Shopee | `SHOPEE_PARTNER_ID` | ID do partner | ⭐⭐⭐ ALTA |
| 🛍️ Shopee | `SHOPEE_PARTNER_KEY` | API Key | ⭐⭐⭐ ALTA |
| 🎵 TikTok | `TIKTOK_CLIENT_ID` | Client ID | ⭐⭐⭐ ALTA |
| 🎵 TikTok | `TIKTOK_CLIENT_SECRET` | Client Secret | ⭐⭐⭐ ALTA |
| 📤 FTP | `FTP_SERVER` | Host do servidor | ⭐⭐⭐ ALTA |
| 📤 FTP | `FTP_USERNAME` | Usuário FTP | ⭐⭐⭐ ALTA |
| 📤 FTP | `FTP_PASSWORD` | Senha FTP | ⭐⭐⭐ ALTA |
| 📧 Email | `EMAIL_FROM` | Email remetente | ⭐⭐ MÉDIA |
| 📧 Email | `EMAIL_TO` | Email destino | ⭐⭐ MÉDIA |
| 📧 Email | `EMAIL_SMTP_HOST` | Host SMTP | ⭐⭐ MÉDIA |
| 📧 Email | `EMAIL_SMTP_PORT` | Porta SMTP | ⭐⭐ MÉDIA |
| 📧 Email | `EMAIL_USER` | Usuário SMTP | ⭐⭐ MÉDIA |
| 📧 Email | `EMAIL_PASSWORD` | Senha SMTP | ⭐⭐ MÉDIA |
| 📦 Olist | `OLIST_CLIENT_ID` | Client ID | ⭐ BAIXA |
| 📦 Olist | `OLIST_CLIENT_SECRET` | Client Secret | ⭐ BAIXA |

---

## 🚀 COMO CONFIGURAR

### OPÇÃO 1: Via GitHub Web Interface (Recomendado)

#### Passo 1: Acessar Secrets
1. Abra: https://github.com/fredmourao-ai/site-shopvivaliz
2. Clique em **Settings**
3. Clique em **Secrets and variables** → **Actions**
4. Você verá: "Repository secrets"

#### Passo 2: Adicionar Primeiro Secret (Exemplo: OPENAI_API_KEY)
1. Clique em **New repository secret**
2. Nome: `OPENAI_API_KEY`
3. Valor: `sk-proj-xxxxx...` (sua chave real)
4. Clique em **Add secret**

#### Passo 3: Repetir para os outros
```
Ordem recomendada:
1. OPENAI_API_KEY (para gerar imagens)
2. SHOPEE_PARTNER_ID (para Shopee)
3. SHOPEE_PARTNER_KEY
4. TIKTOK_CLIENT_ID (para TikTok)
5. TIKTOK_CLIENT_SECRET
6. FTP_SERVER (para upload)
7. FTP_USERNAME
8. FTP_PASSWORD
9. EMAIL_FROM (para notificações)
10. EMAIL_TO
11. EMAIL_SMTP_HOST
12. EMAIL_SMTP_PORT
13. EMAIL_USER
14. EMAIL_PASSWORD
```

#### ✅ Resultado
Você verá uma lista assim:
```
✓ OPENAI_API_KEY                Updated 2 seconds ago
✓ SHOPEE_PARTNER_ID            Updated 1 second ago
✓ SHOPEE_PARTNER_KEY           Updated now
✓ TIKTOK_CLIENT_ID             Updated now
... (mais secrets)
```

---

### OPÇÃO 2: Via GitHub CLI (Automático)

Se você tem `gh` CLI instalado:

```bash
# 1. Fazer login no GitHub (primeira vez)
gh auth login

# 2. Configurar secrets (cada um pede o valor)
gh secret set OPENAI_API_KEY           # Vai pedir: sk-proj-...
gh secret set SHOPEE_PARTNER_ID        # Vai pedir: 1237032
gh secret set SHOPEE_PARTNER_KEY       # Vai pedir: shpk_...
gh secret set TIKTOK_CLIENT_ID         # Vai pedir: 7...
gh secret set TIKTOK_CLIENT_SECRET     # Vai pedir: secret_...
gh secret set FTP_SERVER               # Vai pedir: ftp.shopvivaliz.com.br
gh secret set FTP_USERNAME             # Vai pedir: usuario_ftp
gh secret set FTP_PASSWORD             # Vai pedir: senha_ftp
gh secret set EMAIL_FROM               # Vai pedir: noreply@...
gh secret set EMAIL_TO                 # Vai pedir: seu@email.com
gh secret set EMAIL_SMTP_HOST          # Vai pedir: smtp.gmail.com
gh secret set EMAIL_SMTP_PORT          # Vai pedir: 587
gh secret set EMAIL_USER               # Vai pedir: seu@email.com
gh secret set EMAIL_PASSWORD           # Vai pedir: app-password

# 3. Verificar secrets configurados
gh secret list
```

---

## 📌 VALORES ESPERADOS

### 🤖 OPENAI_API_KEY
```
Onde: https://platform.openai.com/api-keys
Formato: sk-proj-xxxxxxxxxxxxxxxxxxxxxxxx
Uso: Gerar imagens IA (4 variantes)
```

### 🛍️ SHOPEE_PARTNER_ID e SHOPEE_PARTNER_KEY
```
Onde: https://partner.shopee.com.br/
Seção: Apps → Settings → API Key
Formato:
  ID: 1237032
  Key: shpk_xxxxxxxxxxxx
Uso: Atualizar produtos em Shopee
```

### 🎵 TIKTOK_CLIENT_ID e TIKTOK_CLIENT_SECRET
```
Onde: https://seller.tiktok.com/ → Settings → Developer
Formato:
  ID: 7xxxxxxxxxx
  Secret: xxxxxxxxxxx
Uso: Atualizar produtos em TikTok Shop
```

### 📤 FTP_SERVER, USERNAME, PASSWORD
```
Onde: Seu provedor de FTP
Exemplo:
  Server: ftp.shopvivaliz.com.br
  User: usuario_ftp
  Pass: senha_segura_123
Uso: Upload de imagens para servidor
```

### 📧 EMAIL_*
```
Para Gmail:
  SMTP_HOST: smtp.gmail.com
  SMTP_PORT: 587
  USER: seu@gmail.com
  PASSWORD: abc123xyz456 (app password, não senha normal)
    
Como gerar app password Gmail:
  1. Acesse: myaccount.google.com/app-passwords
  2. Selecione: Gmail + Windows
  3. Copie a senha gerada (16 caracteres)
  4. Cole aqui como EMAIL_PASSWORD
```

---

## ✅ VERIFICAR CONFIGURAÇÃO

### Via GitHub Web
1. Vá para: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
2. Veja quantos secrets estão configurados
3. Mínimo para funcionar: 8 secrets (destacados com ⭐⭐⭐)

### Via CLI
```bash
gh secret list
```

Resultado esperado:
```
name                      updated_at
OPENAI_API_KEY           2026-06-29
SHOPEE_PARTNER_ID        2026-06-29
SHOPEE_PARTNER_KEY       2026-06-29
TIKTOK_CLIENT_ID         2026-06-29
TIKTOK_CLIENT_SECRET     2026-06-29
FTP_SERVER               2026-06-29
FTP_USERNAME             2026-06-29
FTP_PASSWORD             2026-06-29
... (mais)
```

---

## 🔄 PRÓXIMO PASSO

Depois de configurar os secrets, o pipeline começará a rodar **automaticamente**:

```bash
# Opção 1: Fazer push para disparar pipeline
git push origin main

# Opção 2: Executar localmente
cd scripts/
python main_advanced.py

# Opção 3: Verificar execução no GitHub
Vá para: https://github.com/fredmourao-ai/site-shopvivaliz/actions
```

---

## 🛠️ TROUBLESHOOTING

### ❌ "Secret not found" ou "401 Unauthorized"
**Problema:** Secret não está configurado ou valor está errado

**Solução:**
1. Verifique em: https://github.com/.../settings/secrets/actions
2. Confirme que o nome está exato (maiúsculas/minúsculas importam)
3. Delete e reconfigure: **Delete secret** → **New secret**

### ❌ "FTP Connection Refused"
**Problema:** Servidor FTP não responde

**Solução:**
```bash
# Testar FTP localmente
curl -v ftp://usuario:senha@ftp.servidor.com/

# Verificar credenciais
ping ftp.servidor.com
```

### ❌ "OpenAI Rate Limit"
**Problema:** Muitas requisições à API

**Solução:**
- Adicionar delays entre requisições
- Aumentar timeout
- Usar batch processing

### ❌ "Shopee 401 Unauthorized"
**Problema:** Credenciais Shopee inválidas

**Solução:**
1. Acesse: https://partner.shopee.com.br/
2. Regenere as API keys
3. Atualize no GitHub Secrets

---

## 📊 SEGURANÇA

✅ **GitHub Secrets são seguros porque:**
- Encriptados em repouso
- Nunca aparecem em logs
- Nunca imprimem em console
- Mascarados em outputs
- Acessíveis apenas em GitHub Actions
- Você controla quem tem acesso

❌ **NUNCA faça isso:**
```bash
# ❌ ERRADO - vai expor o secret!
export SHOPEE_PARTNER_KEY=shpk_xxxxx
git commit

# ✅ CERTO - usar GitHub Secrets
# Secret fica seguro no GitHub
```

---

## 📈 CHECKLIST

- [ ] Acessar https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets
- [ ] Configurar OPENAI_API_KEY
- [ ] Configurar SHOPEE_PARTNER_ID
- [ ] Configurar SHOPEE_PARTNER_KEY
- [ ] Configurar TIKTOK_CLIENT_ID
- [ ] Configurar TIKTOK_CLIENT_SECRET
- [ ] Configurar FTP_SERVER
- [ ] Configurar FTP_USERNAME
- [ ] Configurar FTP_PASSWORD
- [ ] Configurar EMAIL_* (opcional)
- [ ] Verificar com: `gh secret list`
- [ ] Fazer push: `git push origin main`
- [ ] Monitorar execução em GitHub Actions
- [ ] ✅ Sistema começará upload automático!

---

## 🎉 RESULTADO

Quando todos os secrets estiverem configurados:

```
git push origin main
  ↓
GitHub Actions dispara
  ↓
Pipeline começa:
  1. Prioriza produtos
  2. Gera SEO
  3. Gera imagens (4 variantes)
  4. Faz A/B testing
  5. Auto-otimiza
  6. ✅ UPLOAD PARA SHOPEE E TIKTOK
  7. Analytics
  ↓
Produtos atualizados em tempo real! 🚀
```

---

**Tudo seguro, automático e sem expor credenciais!** 🔐

Dúvidas? Consulte: [README_AGENTES.md](README_AGENTES.md)
