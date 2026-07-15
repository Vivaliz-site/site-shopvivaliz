# Task-033 Fase 2 — Stock Alerts Email CRON

## 📋 Objetivo
Implementar notificações automáticas via email quando produtos voltam ao estoque.

## ✅ Componentes Implementados

### 1. Script CRON Python
**Arquivo:** `scripts/stock-alerts-email-cron.py`

**Funcionamento:**
- Consulta banco de dados para produtos que voltaram ao estoque
- Filtra registros em `stock_alerts` table que ainda não foram notificados
- Envia email HTML formatado para cada usuário inscrito
- Registra envio em `notified_at` field

**Variáveis de Ambiente Requeridas:**
```
SMTP_HOST     = servidor SMTP (ex: mail.gmail.com)
SMTP_PORT     = porta SMTP (ex: 587)
SMTP_USER     = usuário SMTP
SMTP_PASS     = senha SMTP
EMAIL_FROM    = email remetente (ex: noreply@shopvivaliz.com.br)
```

**Segurança:**
- Se credenciais SMTP não existirem, script faz skip de envio real
- Usa `unsubscribe_token` único por inscrição para desincrever

---

### 2. Workflow GitHub Actions
**Arquivo:** `.github/workflows/stock-alerts-email-cron.yml`

**Schedule:** A cada 30 minutos (`*/30 * * * *`)

**Trigger Manual:** `workflow_dispatch` (para testar)

---

### 3. Migração do Banco de Dados
**Arquivo:** `migrations/20260712_add_stock_alerts_table.sql`

**Tabela:** `stock_alerts`

**Schema:**
```sql
id                  INTEGER PRIMARY KEY
sku                 VARCHAR(100)         -- SKU do produto
email               VARCHAR(255)         -- Email do usuário
unsubscribe_token   VARCHAR(64) UNIQUE   -- Token para desincrever
created_at          DATETIME             -- Quando se inscreveu
notified_at         DATETIME NULL        -- Quando foi notificado
notified_count      INTEGER DEFAULT 0    -- Quantas notificações enviou

UNIQUE(sku, email)  -- Evita duplicatas
```

---

## 🔧 Instalação/Setup

### Pré-requisito: Configurar Secrets no GitHub

No GitHub Settings > Secrets and variables > Actions, adicionar:

```
SMTP_HOST      = (seu servidor SMTP)
SMTP_PORT      = (porta, ex: 587)
SMTP_USER      = (usuario@gmail.com)
SMTP_PASS      = (senha de app)
EMAIL_FROM     = (noreply@shopvivaliz.com.br)
```

**Teste com Gmail:**
```
SMTP_HOST = smtp.gmail.com
SMTP_PORT = 587
SMTP_USER = seu-email@gmail.com
SMTP_PASS = (app-specific password)
```

### Executar Migração
```bash
# No servidor (ou localmente)
sqlite3 data/shopvivaliz.db < migrations/20260712_add_stock_alerts_table.sql
```

### Testar Script Localmente
```bash
export SMTP_HOST=smtp.gmail.com
export SMTP_PORT=587
export SMTP_USER=seu-email@gmail.com
export SMTP_PASS=seu-app-password

python scripts/stock-alerts-email-cron.py
```

---

## 📊 Fluxo Completo

### Usuário
1. Acessa produto com estoque = 0
2. Clica em "Avise-me quando chegar!"
3. Preenche email e clica em enviar
4. API `/api/catalog/stock-alert.php` insere em `stock_alerts` table

### Sistema
1. CRON executa a cada 30 min
2. Verifica `stock_alerts` com `notified_at IS NULL`
3. Verifica `products` table — se `stock > 0`, produto voltou!
4. Envia email com link para desinscrever
5. Atualiza `notified_at` com data/hora
6. Se mesmo produto voltar ao estoque novamente (stock zerou e depois > 0), pode renotificar (remove `notified_at` na re-inscrição)

---

## 🧪 Teste End-to-End

### 1. Teste Local (sem SMTP real)
```bash
python scripts/stock-alerts-email-cron.py
# Deve imprimir: [AVISO] Credenciais SMTP não configuradas - skipando envio real
```

### 2. Teste GitHub Actions (manual)
```
GitHub > Actions > Stock Alerts Email CRON > Run workflow > Run workflow
```

### 3. Teste em Produção
1. Configurar SMTP secrets em GitHub
2. Aguardar próxima execução automática (30 min)
3. Verificar logs em GitHub Actions
4. Verificar email inbox

---

## ⚙️ Variantes de Execução

### Executar fora do GitHub (cronjob direto no servidor)

No servidor (`/home/ubuntu/site-shopvivaliz`):

```bash
# Editar crontab
crontab -e

# Adicionar linha:
*/30 * * * * cd /home/ubuntu/site-shopvivaliz && python3 scripts/stock-alerts-email-cron.py >> logs/stock-alerts-cron.log 2>&1
```

---

## 📝 Próximos Passos (Fase 3)

- [ ] SMS notifications (integrar Twilio)
- [ ] Push notifications (integrar Firebase)
- [ ] Dashboard admin para visualizar alertas
- [ ] Analytics — quantos alertas geraram vendas
- [ ] AB test — qual canal (email/SMS/push) converte melhor

---

**Status:** ✅ Fase 2 Pronta para Deploy

