# Auditoria de Pipelines GitHub Actions - ShopVivaliz

Data: 2026-06-29
Total de Workflows: 35

## ACHADOS - DUPLICATAS IDENTIFICADAS

### 1. AUTOMACAO DE PRODUTOS 24/7 (PRINCIPAL) 🔴 **CRITICA**

| Arquivo | Linhas | Trigger | Funcao | Status |
|---------|--------|---------|--------|--------|
| **automation-autonoma-24-7.yml** | 105 | A cada 6h (0 */6) | Pipeline completo de produtos | ✅ **MANTER** |
| shopvivaliz-ai-pipeline.yml | 317 | A cada 6h (0 */6) | IA pipeline (mais completo) | ⚠️ **ANALISAR** |
| shopvivaliz-autonomous-maestro.yml | 241 | A cada 6h (0 */6) + push | Maestro automacao | ⚠️ **ANALISAR** |
| autonomous-agents-24-7.yml | 138 | A cada 1h (0 * ) | Agentes analisam tarefas | ✅ **MANTER** (diferente) |
| 24-7-continuous-agent.yml | 87 | A cada 5min (*/5) | Executa tarefas | ❌ **DELETAR** (script nao existe) |

**RECOMENDACAO:**
- **MANTER:** `automation-autonoma-24-7.yml` (nosso pipeline principal criado recentemente)
- **MANTER:** `shopvivaliz-ai-pipeline.yml` (mais completo, com inputs customizaveis)
- **DELETAR:** `shopvivaliz-autonomous-maestro.yml` (duplicado, similar ao anterior)
- **DELETAR:** `24-7-continuous-agent.yml` (referencia script inexistente)

---

### 2. OLIST SYNC / SINCRONIZACAO 🟡 **MEDIA**

| Arquivo | Linhas | Funcao | Status |
|---------|--------|--------|--------|
| olist-auto-sync-hourly.yml | 77 | Sincronizar produtos Olist 1x/h | ⚠️ **ANALISAR** |
| sync-olist-images-v2.yml | 98 | Sincronizar imagens Olist 1x/d | ✅ **MANTER** |
| export-olist-images-csv.yml | ? | Exportar CSV de imagens | ⚠️ **ANALISAR** |
| fetch-shopee-listings.yml | 76 | Fetch Shopee via Tiny API | ⚠️ **ANALISAR** |

**RECOMENDACAO:**
- **MANTER:** `sync-olist-images-v2.yml` (v2 mais novo)
- **DELETAR:** `olist-auto-sync-hourly.yml` (versao antiga, mesma funcao)

---

### 3. DEPLOY PIPELINES 🟡 **MEDIA**

| Arquivo | Linhas | Trigger | Funcao |
|---------|--------|---------|--------|
| deploy.yml | 220 | Manual (workflow_dispatch) | Deploy automatico seguro |
| shopvivaliz-ai-pipeline.yml | 317 | Agendado (6h) | IA Pipeline + Deploy |
| shopvivaliz-autonomous-maestro.yml | 241 | Agendado (6h) + push + manual | Maestro + Deploy |

**RECOMENDACAO:**
- Manter `deploy.yml` para deploy manual
- `shopvivaliz-ai-pipeline.yml` pode servir como pipeline completo agendado

---

### 4. MONITORAMENTO/CHAT

| Arquivo | Funcao | Status |
|---------|--------|--------|
| monitor-chat-responses.yml | Monitorar respostas chat | ⚠️ Verificar |
| monitor-chat-responder.yml | Responder chats | ⚠️ Verificar |
| deploy-squad-chat.yml | Deploy squad chat | ✅ Manter |

**RECOMENDACAO:** Verificar se sao realmente necessarios

---

### 5. VALIDACAO/QA

| Arquivo | Linhas | Funcao |
|---------|--------|--------|
| auto-validation-and-fix.yml | 108 | Auto-validacao e fix |
| ai-agent-review.yml | 152 | Review com agentes IA |
| shopvivaliz-qa.yml | ? | QA pipeline |
| external-smoke-test.yml | ? | Smoke test externo |

**RECOMENDACAO:** Manter apenas um validador principal

---

### 6. SETUP/CONFIG

| Arquivo | Linhas | Funcao |
|---------|--------|--------|
| setup-secrets.yml | ? | Setup de secrets |
| copy-secrets-to-pipeline.yml | 115 | Copy secrets |
| setup-branch-protection.yml | ? | Branch protection |

**RECOMENDACAO:** Consolidar em um unico setup workflow

---

## RESUMO DE ACOES

### ✅ MANTER (Funcionais e necessarios)
1. `automation-autonoma-24-7.yml` - Pipeline principal produtos
2. `shopvivaliz-ai-pipeline.yml` - Pipeline completo IA (mais features)
3. `sync-olist-images-v2.yml` - Sincronizacao Olist
4. `deploy.yml` - Deploy manual
5. `auto-validation-and-fix.yml` - Validacao principal
6. `autonomous-agents-24-7.yml` - Agentes analisam tarefas

### ❌ DELETAR (Duplicados ou obsoletos)
1. `shopvivaliz-autonomous-maestro.yml` - Duplicado de shopvivaliz-ai-pipeline
2. `24-7-continuous-agent.yml` - Script inexistente
3. `olist-auto-sync-hourly.yml` - Versao antiga (v1)
4. `ai-trio-ecommerce.yml` - Obsoleto
5. `parallel-trio-executor.yml` - Obsoleto
6. `continuous-trio-executor.yml` - Obsoleto
7. `executor-watchdog.yml` - Watchdog desnecessario

### ⚠️ CONSOLIDAR
1. `monitor-chat-responses.yml` + `monitor-chat-responder.yml` → Unico workflow
2. `setup-secrets.yml` + `copy-secrets-to-pipeline.yml` → Unico setup

---

## TOPOLOGIA FINAL RECOMENDADA

```
.github/workflows/
├─ automation-autonoma-24-7.yml          [PRINCIPAL - 6h]
├─ shopvivaliz-ai-pipeline.yml           [COMPLETO - 6h]
├─ autonomous-agents-24-7.yml            [AGENTES - 1h]
├─ sync-olist-images-v2.yml              [OLIST - 1x/dia]
├─ deploy.yml                            [MANUAL]
├─ auto-validation-and-fix.yml           [VALIDACAO]
├─ monitor-chat-unified.yml              [CHAT]
└─ setup-pipeline.yml                    [CONFIG]
```

