# 🔐 Guia de Configuração de Secrets - ShopVivaliz

## Status Atual

```
✅ Configurados: 0/20
❌ Faltando: 20/20
📈 Taxa de Conclusão: 0%
```

---

## 📋 Opção 1: Configuração Manual (Recomendado para Produção)

### Via GitHub Web Interface

1. Acesse: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
2. Clique em "New repository secret"
3. Adicione cada secret conforme a tabela abaixo

### Via GitHub CLI (Terminal)

```bash
# Instale o GitHub CLI primeiro (https://cli.github.com/)

# Configure um secret
gh secret set NOME_DO_SECRET

# Exemplo:
gh secret set ANTHROPIC_API_KEY
# Digite o valor quando solicitado

# Verifique todos os secrets
gh secret list
```

---

## 🔑 Secrets Necessários

### 1️⃣ API Keys (Obrigatório)

```
ANTHROPIC_API_KEY
├─ Descrição: Chave API da Anthropic (Claude)
├─ Como obter: https://console.anthropic.com/
├─ Formato: sk-ant-...
└─ Prioridade: ⭐⭐⭐ ALTA

OPENAI_API_KEY
├─ Descrição: Chave API do OpenAI (GPT)
├─ Como obter: https://platform.openai.com/api-keys
├─ Formato: sk-...
└─ Prioridade: ⭐⭐ MÉDIA

GEMINI_API_KEY
├─ Descrição: Chave API do Google Gemini
├─ Como obter: https://aistudio.google.com/app/apikey
├─ Formato: AIza...
└─ Prioridade: ⭐⭐ MÉDIA
```

### 2️⃣ Credenciais Olist (Obrigatório para Sync)

```
OLIST_CLIENT_ID
├─ Descrição: ID do cliente Olist
├─ Como obter: Dashboard Olist > Integrações
├─ Formato: Numérico ou código
└─ Prioridade: ⭐⭐⭐ ALTA

OLIST_CLIENT_SECRET
├─ Descrição: Secret do cliente Olist
├─ Como obter: Dashboard Olist > Integrações
├─ Formato: String criptografada
└─ Prioridade: ⭐⭐⭐ ALTA

TOKEN_API_OLIST
├─ Descrição: Token API da Olist
├─ Como obter: Dashboard Olist > API
├─ Formato: Token único
└─ Prioridade: ⭐⭐⭐ ALTA
```

### 3️⃣ Credenciais FTP (Obrigatório para Upload)

```
FTP_SERVER
├─ Descrição: Host do servidor FTP
├─ Exemplo: ftp.shopvivaliz.com.br
└─ Prioridade: ⭐⭐⭐ ALTA

FTP_USERNAME
├─ Descrição: Usuário FTP
├─ Exemplo: usuario_ftp
└─ Prioridade: ⭐⭐⭐ ALTA

FTP_PASSWORD
├─ Descrição: Senha FTP
├─ Aviso: Manter em segredo!
└─ Prioridade: ⭐⭐⭐ ALTA
```

### 4️⃣ Credenciais Email (Recomendado)

```
EMAIL_FROM
├─ Descrição: Email remetente
├─ Exemplo: noreply@shopvivaliz.com.br
└─ Prioridade: ⭐⭐ MÉDIA

EMAIL_TO
├─ Descrição: Email destinatário (relatórios)
├─ Exemplo: admin@shopvivaliz.com.br
└─ Prioridade: ⭐⭐ MÉDIA

EMAIL_SMTP_HOST
├─ Descrição: Host SMTP
├─ Exemplo: smtp.gmail.com
└─ Prioridade: ⭐⭐ MÉDIA

EMAIL_SMTP_PORT
├─ Descrição: Porta SMTP
├─ Default: 587
└─ Prioridade: ⭐ BAIXA

EMAIL_USER
├─ Descrição: Usuário SMTP
├─ Exemplo: seu-email@gmail.com
└─ Prioridade: ⭐⭐ MÉDIA

EMAIL_PASSWORD
├─ Descrição: Senha SMTP
├─ Nota: Use app password para Gmail
└─ Prioridade: ⭐⭐ MÉDIA
```

### 5️⃣ Credenciais Database (Recomendado)

```
DB_HOST
├─ Descrição: Host do banco de dados
├─ Exemplo: localhost ou IP
└─ Prioridade: ⭐⭐ MÉDIA

DB_NAME
├─ Descrição: Nome do banco de dados
├─ Exemplo: shopvivaliz
└─ Prioridade: ⭐⭐ MÉDIA
```

### 6️⃣ Credenciais Marketplace (Opcional)

```
SHOPEE_PARTNER_ID
├─ Descrição: ID Partner Shopee
├─ Como obter: Shopee Partner Center
└─ Prioridade: ⭐⭐ MÉDIA

SHOPEE_PARTNER_KEY
├─ Descrição: Key Partner Shopee
├─ Como obter: Shopee Partner Center
└─ Prioridade: ⭐⭐ MÉDIA

TIKTOK_CLIENT_ID
├─ Descrição: ID Cliente TikTok Shop
├─ Como obter: TikTok Developer Console
└─ Prioridade: ⭐ BAIXA

TIKTOK_CLIENT_SECRET
├─ Descrição: Secret TikTok Shop
├─ Como obter: TikTok Developer Console
└─ Prioridade: ⭐ BAIXA
```

---

## 📝 Opção 2: Configuração Automática (Desenvolvimento)

### Via Script Python

```bash
# 1. Configure variáveis de ambiente
export ANTHROPIC_API_KEY="sk-ant-..."
export OPENAI_API_KEY="sk-..."
export FTP_SERVER="ftp.shopvivaliz.com.br"
# ... configure outros secrets

# 2. Execute o script de setup
python scripts/setup_secrets.py

# 3. Verifique se foi tudo configurado
python scripts/verify_secrets.py
```

### Via arquivo .env (Desenvolvimento Only)

```bash
# 1. Crie um arquivo .env na raiz
echo "
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
FTP_SERVER=ftp.shopvivaliz.com.br
FTP_USERNAME=usuario
FTP_PASSWORD=senha
" > .env

# 2. Execute o script
python scripts/setup_secrets.py

# 3. Não commite o arquivo .env!
# Ele já está no .gitignore
```

---

## ✅ Checklist de Configuração

### Essencial (para executar pipeline)
- [ ] ANTHROPIC_API_KEY
- [ ] OLIST_CLIENT_ID
- [ ] OLIST_CLIENT_SECRET
- [ ] FTP_SERVER
- [ ] FTP_USERNAME
- [ ] FTP_PASSWORD

### Importante (para notificações)
- [ ] EMAIL_FROM
- [ ] EMAIL_TO
- [ ] EMAIL_SMTP_HOST
- [ ] EMAIL_USER
- [ ] EMAIL_PASSWORD

### Opcional (para marketplaces extras)
- [ ] SHOPEE_PARTNER_ID
- [ ] SHOPEE_PARTNER_KEY
- [ ] TIKTOK_CLIENT_ID
- [ ] TIKTOK_CLIENT_SECRET

---

## 🧪 Teste de Secrets

### Verificar quais secrets estão configurados

```bash
python scripts/verify_secrets.py
```

### Output esperado

```
✅ Configurados: 6/20 (30%)
❌ Faltando: 14/20
📈 Taxa de Conclusão: 30%
```

---

## 🚀 Próximos Passos

1. **Configure os secrets** usando uma das opções acima
2. **Execute o pipeline** de teste:
   ```bash
   python scripts/run_pipeline_test.py
   ```
3. **Verifique os logs**:
   ```bash
   cat logs/pipeline_execution.json
   ```
4. **Verifique os relatórios**:
   - `logs/ab_test_report.txt`
   - `logs/optimization_report.txt`

---

## 🔒 Segurança

⚠️ **IMPORTANTE:**
- Nunca commite credentials no repositório
- Use GitHub Secrets para dados sensíveis
- Regenere tokens/passwords regularmente
- Monitore o acesso aos secrets

---

**Última atualização:** 29/06/2026
**Status:** ⏳ Aguardando configuração de secrets
