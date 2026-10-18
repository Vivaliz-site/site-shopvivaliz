# P1: Reconciliação de Bancos de Dados — Plano de Execução

**Data:** 2026-10-18  
**Status:** 🔴 EM EXECUÇÃO

## Objetivo P1

Validar integridade completa de dados MySQL e PostgreSQL entre **origem** (shopvivaliz-free-a1) e **destino** (Marina). Sincronizar deltas identificados sob controle com rollback testado.

## Bancos e Tabelas Críticas

### MySQL — shopvivaliz (102 tabelas)

**Core:**
- orders (pedidos)
- products (catálogo)
- users (clientes)
- oauth_tokens (Tiny/Olist)

**Verificar:** Integridade de chaves, foreign keys, timestamps, referências.

### MySQL — amazon_returns_safet (16 tabelas)

**Core:**
- amazon_return_cases (casos Amazon)
- amazon_return_decisions (decisões)
- tenant_registry (multi-tenant)

**Verificar:** Delta de casos novo na Marina vs. origem; transações abertas.

### MySQL — ml_pricing_prod (27 tabelas)

**Core:**
- ml_pricing_rules
- ml_pricing_feeds
- ml_pricing_history

**Verificar:** Continuidade de histórico; integridade de índices.

### PostgreSQL — MLRR

**Bloqueio Conhecido:** 1 registro ausente na Marina (identificar qual).

**Core:**
- orders, decisions, appeals, reconciliation

**Verificar:** UUID, timestamps, status transacional.

### PostgreSQL — Supabase (validação)

**Objetivo:** Leitura apenas; não mutar em produção.

## Estratégia de Sincronização

### Fase 1: Diagnóstico (RED)

```bash
# Para cada banco:
# 1. Contar registros (origem vs. Marina)
# 2. Conferir checksums de tabelas críticas
# 3. Validar referências (foreign keys)
# 4. Identificar deltas explicitamente
```

**Saída esperada:**
- ✅ Tabela X: 1000 registros em ambos (OK)
- ❌ Tabela Y: 500 (origem) vs. 499 (Marina) — 1 faltando
- ⚠️ Tabela Z: índice corrompido em Marina

### Fase 2: Análise de Deltas (RED)

```bash
# Para cada delta identificado:
# 1. Que registro falta / está a mais?
# 2. Qual é a causa raiz? (não sincronizado, novo, deletado)
# 3. Qual é a direção correta? (origem → Marina ou Marina remove?)
```

### Fase 3: Sincronização Controlada (YELLOW)

**Nunca sobrescrever destino com dados antigos:**

- Origem → Marina: apenas deltas reais + novos registros
- Marina ← Origem: apenas para remover orfãos confirmados
- **Reverso:** nunca (Marina é a verdade para workers já ativados)

**Método:**
1. Backup de ambos antes de qualquer mutação
2. Script idempotente (rodar 2x = rodar 1x)
3. Validação pós-sincronização
4. Rollback testado

### Fase 4: Validação (GREEN)

```bash
# Depois de sincronizar:
# 1. Refaz diagnóstico completo
# 2. Confirma que deltas foram resolvidos
# 3. Testa queries críticas (não falharam)
# 4. Valida integridade de foreign keys
```

## Execução Passo a Passo

### Passo 1: Recuperar Status via Remote Access Workflow

**Comando:** Disparado
- Target: always-free-arm-1787907847-26 (controladora)
- Action: runtime_status
- Esperado: Logs de saúde, contagens de banco, estado dos workers

**Saída:** Job #39093328435

---

### Passo 2: Analisar Checkpoints Existentes

**Arquivos esperados:**
- `/home/ubuntu/migration-checks/STATUS.md` (Marina)
- `/home/ubuntu/migration-marina-a1/STATUS.md` (controladora)
- `/home/ubuntu/shopvivaliz-deploy/shared/.migration-snapshot` (origem, opcional)

**Ação:**
1. Ler checkpoints via workflow output
2. Identificar último ponto de sincronização
3. Calcular delta desde então

---

### Passo 3: Criar Script de Diagnóstico MySQL

```bash
#!/bin/bash
# diagnose-mysql-delta.sh — compara shopvivaliz entre origem e Marina

ORIGIN="ubuntu@shopvivaliz-free-a1"
MARINA_DB="amazon_returns_safet"  # mesmo server, outro DB

for table in orders products users oauth_tokens; do
    ORIGIN_COUNT=$(ssh $ORIGIN "mysql -u root -Nse 'SELECT COUNT(*) FROM $table' shopvivaliz")
    MARINA_COUNT=$(mysql -u root -Nse "SELECT COUNT(*) FROM $table" shopvivaliz)
    
    if [ "$ORIGIN_COUNT" -ne "$MARINA_COUNT" ]; then
        echo "❌ DELTA: $table — origin=$ORIGIN_COUNT, marina=$MARINA_COUNT"
    else
        echo "✅ OK: $table — $ORIGIN_COUNT records"
    fi
done
```

---

### Passo 4: Script de Sincronização Segura

```bash
#!/bin/bash
# sync-migration-delta.sh — sincroniza delta identificado

set -Eeuo pipefail

# Backup ANTES de qualquer mutação
mysqldump -u root shopvivaliz > /tmp/shopvivaliz-pre-sync-$(date +%s).sql
mysqldump -u root amazon_returns_safet > /tmp/amazon-pre-sync-$(date +%s).sql

# Sincronizar delta (exemplo: inserir registros faltantes)
# NUNCA sobrescrever; apenas adicionar ou remover confirmados

mysql -u root shopvivaliz << SQL
  INSERT IGNORE INTO products (id, sku, name, ...)
  SELECT id, sku, name, ... FROM origin_snapshot.products
  WHERE id NOT IN (SELECT id FROM products)
SQL

# Validar
mysql -u root -Nse "SELECT COUNT(*) FROM products" shopvivaliz
```

---

### Passo 5: E2E Validation

Após sincronização:

```bash
# 1. Verificar que pedidos podem ser criados
POST /api/checkout/order-create

# 2. Verificar que webhooks funcionam
Simular webhook Tiny: /api/webhook-tiny-order

# 3. Verificar que integrações respondem
GET /api/health — todos os providers devem estar OK
```

---

## Decisões Pendentes

**Q1:** Se encontrar registro faltando em Amazon Returns na Marina, qual é a direção correta?
- A) Sincronizar da origem (origem é verdade)
- B) Deletar da origem (Marina criou novos dados, origin é stale)
- C) Investigar a causa (qual é mais confiável?)

**Q2:** Se encontrar timestamp divergente, qual confio?
- A) Origem (histórico mais longo)
- B) Marina (mais recente)
- C) Precisão de microsegundos (unlikely)

**Q3:** Preciso de downtime para sincronizar?
- A) Não (sincronizar paralelo, workers rodando na Marina)
- B) Sim (parar workers, sincronizar, retomar)
- C) Parcial (origem read-only durante sync)

---

## Checkpoints (Será Atualizado)

- ⏳ Passo 1: Remote workflow disparado (job #39093328435)
- ⏳ Passo 2: Checkpoints recuperados
- ⏳ Passo 3: Diagnóstico MySQL rodado
- ⏳ Passo 4: Delta identificado
- ⏳ Passo 5: Sincronização executada
- ⏳ Passo 6: Validação confirmada
- ⏳ Passo 7: E2E real funciona

---

## Referências

- Missão Codex — Seção 5 (Recuperação e Sincronização Final dos Bancos)
- Checkpoints: `/home/ubuntu/migration-checks/STATUS.md`
- AGENTS.md — regras de migração
