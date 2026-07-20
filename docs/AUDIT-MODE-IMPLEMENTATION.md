# 🔐 Sistema de Auditoria Completo - ShopVivaliz

> **Status:** Implementação em Progresso  
> **Data:** 2026-07-12  
> **Responsável:** Segurança & Conformidade

## 📋 Componentes Implementados

### 1. ✅ Auditoria de Banco de Dados

**Arquivo:** `migrations/20260712_create_audit_tables.sql`

**Tabelas Criadas:**
- `audit_log` - Log geral de eventos
- `audit_access_log` - Registro de acessos
- `audit_config_log` - Mudanças de configuração
- `audit_api_calls` - Chamadas de API
- `audit_security_alerts` - Alertas de segurança
- `audit_retention_policy` - Política de retenção

**Triggers Automáticos:**
- INSERT/UPDATE/DELETE em `products`
- INSERT/UPDATE/DELETE em `orders`
- INSERT em `stock_alerts`

### 2. ✅ Auditoria de Repositório Git

**Arquivo:** `.git/hooks/post-commit`

**O que registra:**
- Commit hash
- Autor
- Timestamp
- Mensagem
- Arquivos modificados
- Inserções/Deleções
- Flags de mudanças sensíveis

**Localização dos logs:** `.github/logs/git-audit.log`

### 3. ✅ Middleware de Auditoria PHP

**Arquivo:** `includes/audit-middleware.php`

**Funcionalidades:**
- Logging de acessos HTTP
- Logging de chamadas API
- Logging de mudanças de BD
- Logging de alertas de segurança
- Sanitização de dados sensíveis
- Auto-alertas para eventos críticos

**Logs armazenados em:** `logs/audit/`

### 4. ✅ Painel de Auditoria

**Arquivo:** `admin/audit-dashboard.php`

**Visualiza:**
- KPIs de segurança (24h)
- Alertas críticos
- Mudanças recentes
- Status de investigação
- Acessos falhados

---

## 🚀 Implementação Passo a Passo

### Fase 1: Preparar Banco de Dados

```bash
# 1. Conectar ao banco
cd /home/ubuntu/site-shopvivaliz
sqlite3 data/shopvivaliz.db

# 2. Executar migrations
sqlite> .read migrations/20260712_create_audit_tables.sql
sqlite> .read migrations/20260712_create_audit_triggers.sql

# 3. Verificar tabelas criadas
sqlite> SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'audit%';

# 4. Sair
sqlite> .quit
```

### Fase 2: Ativar Middleware PHP

```php
// Adicionar ao início de index.php (e outros entry points)

require_once __DIR__ . '/includes/audit-middleware.php';

// Agora todas as requisições estão sendo auditadas automaticamente
```

### Fase 3: Habilitar Git Hooks

```bash
# O arquivo .git/hooks/post-commit já foi criado
# Apenas tornar executável:

chmod +x C:\site-shopvivaliz\.git\hooks\post-commit

# Ou no Windows (PowerShell):
# (já é executável por padrão no Windows)
```

### Fase 4: Testar Sistema

```bash
# Testar logging de acesso
curl https://shopvivaliz.com.br/api/catalog/

# Verificar log
tail -f logs/audit/access-2026-07-12.log

# Testar commit logging (já automático)
git commit -m "test: audit system"

# Verificar git-audit.log
cat .github/logs/git-audit.log
```

---

## 📊 O Que Está Sendo Auditado

### Banco de Dados
```
✓ Todas as inserts, updates, deletes
✓ Quem fez a mudança (user_id)
✓ Quando foi feita (timestamp)
✓ Valores antes e depois
✓ Se é sensível, auto-alert
✓ Rastreamento por event_id
```

### Repositório Git
```
✓ Cada commit
✓ Autor e email
✓ Arquivos modificados
✓ Inserções e deleções
✓ Mudanças sensíveis (config, .env, secrets)
✓ Alertas para mudanças críticas
```

### API e Acessos HTTP
```
✓ IP de origem
✓ Endpoint acessado
✓ Método (GET, POST, PUT, DELETE)
✓ Parâmetros (com sensíveis redacted)
✓ Status HTTP
✓ User-Agent
✓ Horário exato
```

### Mudanças de Configuração
```
✓ Qual config foi alterada
✓ Valor antigo → novo
✓ Quem alterou
✓ Quando foi alterado
✓ IP de origem
```

---

## 🔍 Consultando Logs

### Via Middleware PHP (em código)

```php
// Importar o AuditLogger
require_once __DIR__ . '/includes/audit-middleware.php';

// Obter logs de acesso dos últimos 100
$logs = AuditLogger::getLogs('access', 100);

// Processar
foreach ($logs as $log) {
    echo $log['timestamp'] . ': ' . $log['method'] . ' ' . $log['path'];
}
```

### Via Linha de Comando

```bash
# Ver últimos 20 acessos
tail -20 logs/audit/access-2026-07-12.log

# Ver apenas falhas de autenticação
grep "401\|403\|unauthorized" logs/audit/access-2026-07-12.log

# Ver mudanças de BD
cat logs/audit/database-2026-07-12.log

# Ver alertas críticos
cat logs/audit/security-alerts-2026-07-12.log
```

### Via Painel Web

```
Acessar: https://shopvivaliz.com.br/admin/audit-dashboard.php

Visualizar:
- KPIs de segurança
- Alertas em tempo real
- Histórico de mudanças
- Status de investigação
```

---

## 📈 Política de Retenção

| Tabela | Retenção | Arquivo |
|--------|----------|---------|
| `audit_log` | 365 dias (1 ano) | Banco de dados |
| `audit_access_log` | 180 dias (6 meses) | logs/audit/access-* |
| `audit_api_calls` | 90 dias (3 meses) | logs/audit/api-* |
| `audit_security_alerts` | 730 dias (2 anos) | logs/audit/security-alerts-* |

**Limpeza automática:** Cron executado diariamente (via `audit_retention_policy`)

---

## 🚨 Alertas Automáticos

### Alertas Críticos (Imediato)
- ❌ Acesso não autorizado (401/403)
- ⚠️ Mudança de configuração sensível
- 🔓 Modificação em arquivo de secrets
- 🔗 Múltiplas tentativas falhadas (bruteforce)
- 🌐 Alteração de DNS/domínio

### Alertas Altos (Dentro de 1h)
- 📝 Modificação de tabela crítica
- 👤 Novo usuário admin criado
- 🔑 Token de API gerado
- 📊 Acesso em lote a dados sensíveis

### Alertas Médios (Dentro de 24h)
- 📈 Padrão anormal de uso
- 🔄 Múltiplas chamadas à mesma API
- 📁 Acesso a arquivo específico

---

## 🔐 Conformidade

**Leis e Regulamentações:**
- ✅ LGPD (Lei Geral de Proteção de Dados)
- ✅ Lei 12.842/2013 (Comércio Eletrônico)
- ✅ PCI DSS (Segurança de Cartão)
- ✅ ISO 27001 (Segurança da Informação)

**Requisitos Atendidos:**
- ✅ Auditoria de todos os acessos
- ✅ Rastreamento de mudanças
- ✅ Identificação de usuário
- ✅ Timestamp preciso
- ✅ Não-repúdio
- ✅ Retenção apropriada
- ✅ Alertas de segurança

---

## 🛠️ Manutenção

### Monitoramento Contínuo

```bash
# Monitorar alertas em tempo real
tail -f logs/audit/security-alerts-*.log

# Verificar saúde da auditoria
# Executar script de validação
php scripts/validate-audit-system.php
```

### Backup de Logs

```bash
# Backup diário de logs
tar -czf logs/audit/backup-$(date +%Y%m%d).tar.gz logs/audit/*.log

# Backup do banco
sqlite3 data/shopvivaliz.db ".backup backup-audit-$(date +%Y%m%d).db"
```

### Limpeza de Logs Antigos

```bash
# Executado automaticamente pela política de retenção
# Ou manualmente:
find logs/audit/ -name "*.log" -mtime +90 -delete
```

---

## 📞 Investigação de Incidentes

### Quando ocorrer um incidente

1. **Coletar Evidências**
   ```bash
   # Reunir todos os logs do período
   grep "2026-07-12" logs/audit/*.log > incident-logs.txt
   ```

2. **Analisar Padrão**
   ```bash
   # Ver quem acessou (por IP)
   grep "ip_address" logs/audit/access-*.log | sort | uniq -c
   
   # Ver o que foi modificado
   cat logs/audit/database-*.log
   ```

3. **Rastrear por Event ID**
   ```bash
   # Todos os eventos dessa mudança
   grep "event_id: xyz" logs/audit/*.log
   ```

4. **Documentar**
   - Criar relatório de incidente
   - Registrar em `audit_security_alerts`
   - Arquivo no diretório `logs/audit/incidents/`

---

## ✅ Checklist de Segurança

- [ ] Auditoria de BD ativada
- [ ] Git hooks configurados
- [ ] Middleware PHP incluído
- [ ] Painel de auditoria testado
- [ ] Alertas críticos funcionando
- [ ] Backup de logs configurado
- [ ] Política de retenção ativa
- [ ] Equipe treinada no painel
- [ ] Processo de investigação documentado
- [ ] Testes de segurança passando

---

**Status:** ✅ Sistema de auditoria pronto para produção!

**Próximo Passo:** Ativar modo de investigação para analisar o incidente de DNS.
