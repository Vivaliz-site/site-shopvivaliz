# Operações 24/7 ShopVivaliz — Pipeline Consolidado

**Status:** ✅ Produção — Sistema automatizado, sem intervenção manual.

> Última atualização: 2026-07-10 — Pipeline consolidado de 59 para 5 workflows ativos.

---

## 📊 Arquitetura Simplificada

```
┌──────────────────────────────────────────────────────┐
│ Developer Push → main                                │
└────────────────┬─────────────────────────────────────┘
                 │
        ┌────────▼────────┐
        │  master-prod    │  Orquestra tudo
        │  uction-        │  1. Validate (lint + wildcard CSS block)
        │  pipeline.yml   │  2. Test Real (smoke + regression)
        │ (novo, master) │  3. Deploy Ready (log intenção)
        └────────┬────────┘  4. Monitor (health check)
                 │
    ┌────────────┴────────────┐
    │                         │
  ✓ PASS                     ✗ FAIL
    │                         │
    ▼                         ▼
Deploy queued to           Rollback:
Oracle VM cron             revert HEAD
(sync next 30 min)         (auto or manual)
    │
    ▼
VM Oracle cron:            ┌─────────────────┐
*/30 min                   │ autonomous-     │  Monitor
git-auto-sync.py           │ watchdog.yml    │  • Health checks
                           │ (*/15 min)      │  • Task execution
                           └─────────────────┘
```

---

## ✅ Workflows Ativos (5 total)

### 1. `master-production-pipeline.yml` — **NOVO, MASTER**
- **Trigger:** Push para `main` (automático) ou `workflow_dispatch` (manual)
- **Estágios:**
  1. **Validate** — PHP lint, CSS wildcard block, no fake data, no secrets
  2. **Test Real** — Smoke test homepage, regression checks (gradients, footer data)
  3. **Deploy Ready** — Log intenção, upload artifact
  4. **Rollback** — Se teste falhar, revert automático
  5. **Monitor** — Espera 5 min, health check, endpoints críticos
- **Timeout:** 15 min total
- **Status:** ✅ **Ativo em produção**

### 2. `shopvivaliz-qa.yml` — Validação (Integrado no Master)
- **Trigger:** Push para `main`, PR, `workflow_dispatch`
- **O que faz:** PHP lint, CSS wildcard block, smoke test
- **Status:** ✅ Mantém-se como fallback; master-pipeline o chama
- **Nota:** Se master-pipeline falhar, shopvivaliz-qa pode ser rodado manualmente

### 3. `autonomous-watchdog.yml` — Monitor 24/7
- **Trigger:** Cron `*/15 * * * *` (a cada 15 min) + `workflow_dispatch`
- **O que faz:** Valida agent key, chama `api/agent/autonomous-watchdog.php`, executa tarefas autônomas
- **Status:** ✅ **Único executor autônomo agora**
- **Nota:** Consolidou `24-7-continuous-agent.yml` (5 min), `ai-autonomous-executor.yml` (30 min), `parallel-trio-executor.yml` (1h)

### 4. Integrações (Mantidas para sincronizar com Shopee/Olist)
- `sync-shopee-6h.yml` — Sincroniza Shopee a cada 6h
- `sync-olist-6h.yml` — Sincroniza Olist a cada 6h
- `shopee-upload-com-secrets.yml` — Upload de imagens Shopee
- `sync-stock-tiny.yml` — Sincroniza estoque Tiny (1x/dia + webhook em tempo real)
- `fetch-shopee-listings.yml` — Fetch listings (6h)
- `optimize-shopee-listings.yml` — Otimizar listings (diária 03h UTC)
- **Status:** ✅ Todas ativas

### 5. Workflows Manuais (Fallback)
- `auto-ftp-deploy.yml` — Deploy FTP HostGator (manual apenas, `workflow_dispatch`)
- `deploy.yml` — Deploy FTP histórico (manual apenas)
- `force-deploy-now.yml` — Força deploy imediato (manual)
- `auto-approve-bot-prs.yml` — Auto-aprova PRs de bots (automático)
- **Status:** ✅ Disponíveis se necessário

---

## 📋 Consolidação: Workflows Pausados (34)

Marcados como **PAUSADO** (não deletados, podem ser reativados):

- **Executores autônomos duplicados:** `ai-autonomous-executor.yml`, `parallel-trio-executor.yml`, `agent-continuous-task-processor.yml`, `autonomous-task-execution.yml`, `v12-execucao-automatica.yml`
- **Ciclos autônomos:** `autonomous-cycle.yml`, `autonomous-orchestrator.yml`, `autonomous-proactive.yml`, `automation-autonoma-24-7.yml`, `autonomous-agents-24-7.yml`, `ci-autonomo-continuo.yml`, `ecommerce-multi-ai-build-24-7.yml`
- **Validação/health redundante:** `auto-validation-and-fix.yml`, `health-check.yml`, `external-smoke-test.yml`, `site-monitor-autofix.yml`, `git-auto-sync-validate.yml`
- **Git operations:** `auto-git-pull.yml`, `auto-git-push.yml`, `auto-task-generator.yml`, `auto-commit.yml`
- **Monitoring:** `monitor-chat-responder.yml`, `monitor-chat-responses.yml`, `hourly-status-email.yml`
- **Setup:** `copy-secrets-to-pipeline.yml`, `setup-branch-protection.yml`, `setup-secrets.yml`, `secret-scan.yml`
- **Outros:** `ai-agent-review.yml`, `ai-pipeline-full.yml`, `package-v9-2-84.yml`, `diag-tiny-api.yml`, `deploy-olist-proxy.yml`, `deploy-squad-chat.yml`, `medusa-eha-next-step-30min.yml`, `auditoria-vazamento-30min.yml`

**Razão:** Todas consolidadas em `master-production-pipeline.yml` ou `autonomous-watchdog.yml`. Liberou ~40% de quota GitHub Actions.

---

## 🔄 Fluxo de Trabalho (24/7)

### 1. Desenvolvimento Local
```bash
# Você edita arquivo
git add .
git commit -m "feat: descrição clara"
git push origin main
```

### 2. GitHub Automático (0-5 min)
```
push → master-production-pipeline.yml inicia
  ├─ Validate (PHP lint, CSS wildcard block, fake data check)
  │   └─ ✓ PASS → próximo estágio
  │   └─ ✗ FAIL → para, logs disponíveis
  │
  ├─ Test Real (smoke test homepage, regression)
  │   └─ ✓ PASS → Deploy Ready
  │   └─ ✗ FAIL → Rollback automático (revert HEAD)
  │
  ├─ Deploy Ready (log intenção, artifact)
  │   └─ Aguarda sync Oracle VM
  │
  └─ Monitor (após 5 min)
      └─ Health check endpoints
      └─ Se falhar: alerta (logs)
```

### 3. Oracle VM Automático (5-30 min)
```
git-auto-sync.py (*/30 * * * *)
  └─ git fetch origin main
  └─ git reset --hard origin/main
  └─ Produção atualizada em dev.shopvivaliz.com.br
```

### 4. Autonomous Monitor (contínuo, 24/7)
```
autonomous-watchdog.yml (*/15 * * * *)
  ├─ Valida agent key
  ├─ Chama api/agent/autonomous-watchdog.php
  ├─ Executa tarefas em tasks-queue.json
  ├─ Auto-commit resultados
  └─ Tenta 3x com backoff exponencial
```

---

## 🛡️ Proteções Contra Regressão

### 1. CSS Wildcard Block (Novo)
```bash
# Bloqueia padrões que já quebraram o site:
# [class*="hero"] → OK (componentes pequenos)
# [class*="layout"] → BLOQUEADO (cascata em layout estrutural)
PADRAO='\[class\s*\*=\s*"(hero|banner|section|page|layout|container)'
```
**Status:** ✅ Ativo em `master-production-pipeline.yml` estágio Validate

### 2. Fake Data Detection (Novo)
```bash
# Bloqueia commits com telefones/emails inventados
FAKE_PHONE='55\s*(11|21|31|85|9)\s*999(9{4,}|0{4,})'
FAKE_EMAIL='(test|fake|dummy|lorem)@'
```
**Status:** ✅ Ativo em `master-production-pipeline.yml` estágio Validate

### 3. Live Smoke Test (Existente)
```bash
# Testa homepage contra site ao vivo
# ✓ Footer tem estrutura
# ✓ Hero não tem gradientes duplicados
# ✓ Não detecta dados fake (WhatsApp, redes sociais)
```
**Status:** ✅ Ativo em `master-production-pipeline.yml` estágio Test Real

### 4. Automatic Rollback (Novo)
```bash
# Se algum teste falha:
git revert HEAD --no-edit
# ou
git reset --hard HEAD~1
git push origin main --force
```
**Status:** ✅ Ativo em `master-production-pipeline.yml` estágio Rollback (se tests falham)

---

## 📊 Quotas GitHub Actions

**Antes (59 workflows):**
- ~40 workflows ativos diariamente
- Estimado: 300-500 execuções/dia
- **Quota esgotada em 2026-07-03** (liberado com delays)

**Depois (5 workflows):**
- ~8-10 execuções/dia
- `master-production-pipeline.yml` (1 por push, ~3-5/dia)
- `autonomous-watchdog.yml` (1 a cada 15 min, ~96/dia)
- `sync-*.yml` (2x/dia cada, ~12/dia)
- **Estimado: 150-200/dia** ✅ Dentro de limite free tier (20k/mês)

**Economia:** ~60% redução, quota crítica liberada.

---

## 🚨 Troubleshooting

### "Pipeline falhou na validação"
```
1. Ver logs em GitHub Actions > master-production-pipeline
2. Comum: CSS wildcard, fake data, syntax error
3. Corrigir localmente, git add, git push (retry automático)
4. Se persistir: GitHub Actions UI > Re-run failed jobs
```

### "Teste falhou, rollback executado"
```
1. Commit anterior foi revertido automaticamente
2. Ver deploy log artifact para saber o que falhou
3. Investigar: smoke test falhou?
   - Possível: site offline temporariamente
   - Solução: GitHub Actions > Re-run job
4. Ou: footer estrutura mudou?
   - Verificar: curl https://shopvivaliz.com.br/ | grep footer-cols
```

### "Oracle VM não sincronizou dentro de 30 min"
```
1. Ver cron na VM: ssh ubuntu@137.131.156.17 "crontab -l | grep git-auto-sync"
2. Forçar sync manual:
   ssh -i <key> ubuntu@137.131.156.17 "cd /home/ubuntu/site-shopvivaliz && python3 git-auto-sync.py"
3. Verificar logs: /var/log/git-auto-sync.log
```

### "autonomous-watchdog executou mas tarefas não rodaram"
```
1. Verificar tasks-queue.json:
   - Há tarefas com status "pending"?
   - Agent key válido?
2. Ver logs em GitHub Actions > autonomous-watchdog
3. Comum: API rate limit (Gemini/Claude/OpenAI)
   - Solução: Retry automático (3x com backoff)
4. Se persistir: rodar manualmente com workflow_dispatch
```

---

## 📞 Referências Rápidas

| Necessidade | Comando | Tempo |
|-----------|---------|-------|
| Ver status deploy | GitHub Actions UI | 1 min |
| Forçar deploy imediato | Force Deploy Now workflow | 2 min |
| Revert commit | GitHub Reverts UI ou git revert | 2 min |
| Ver logs Oracle VM | SSH `/var/log/git-auto-sync.log` | 1 min |
| Executar tarefa IA manual | Adicionar a `tasks-queue.json` + workflow_dispatch | 2 min |
| Ativar workflow pausado | GitHub Actions > Workflow > Enable | 1 min |
| Desabilitar auto-sync | SSH crontab -e na VM | 2 min |

---

## 🎯 Próximos Passos (Otimizações Futuras)

1. **Dashboard em tempo real** — Vercel app que mostra status de deployments
2. **Slack notifications** — Alertas de fail/rollback em #deployments
3. **Automated performance tests** — Core Web Vitals após deploy
4. **Blue-green deployment** — VM secondary para teste antes de produção
5. **Staging environment** — Testar integrações Shopee/Olist em estágio

---

## 📝 Histórico de Mudanças (Pipeline)

| Data | Mudança | Razão |
|------|---------|-------|
| 2026-07-10 | Consolidou 59 → 5 workflows | Quota esgotada, redundâncias, 60% economia |
| 2026-07-10 | Criou master-production-pipeline.yml | Orquestra validate→test→deploy com rollback |
| 2026-07-10 | Adicionou CSS wildcard block | Evita regressão do bug da home |
| 2026-07-10 | Adicionou fake data detection | Evita regressão footer fake |
| 2026-07-09 | Consolidou 24-7-continuous-agent.yml em autonomous-watchdog.yml | 4 workflows fazendo a mesma chamada |

---

**Sistema em produção. 24/7 automático, sem intervenção manual. ✅**
