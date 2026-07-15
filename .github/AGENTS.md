# AGENTS.md — Governança de Agentes AI · ShopVivaliz

> Versão 1.1 · 2026-07-04  
> Documento oficial de governança para todos os agentes AI que operam neste repositório.  
> **Nenhum agente pode ignorar estas regras.**

---

## 1. Organograma

```
                        ┌─────────────┐
                        │   CEO AI    │  Visão de negócio, prioridades estratégicas
                        └──────┬──────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                │
        ┌─────▼─────┐   ┌──────▼─────┐  ┌──────▼──────┐
        │   COO AI  │   │   PMO AI   │  │   CTO AI    │
        │ Operações │   │  Backlog   │  │ Arquitetura │
        └─────┬─────┘   └──────┬─────┘  └──────┬──────┘
              │                │                │
              │         ┌──────▼─────┐   ┌──────▼──────┐
              │         │ Security AI│   │Arquiteto AI │
              │         └────────────┘   └─────────────┘
              │
     ┌────────┼────────────────────┐
     │        │                    │
┌────▼───┐ ┌──▼──────┐   ┌─────────▼──────┐
│  QA AI │ │Release  │   │Observability AI│
│        │ │Manager  │   │ Logs·Métricas  │
└────┬───┘ └──┬──────┘   └────────────────┘
     │        │
     └────┬───┘
          │
  ┌───────▼────────┐
  │Executores      │  OpenRouter (modelos econômicos)
  │operacionais    │  Claude Haiku · GPT-4o-mini · etc.
  └────────────────┘
```

---

## 2. Responsabilidades

| Agente | Responsabilidade | Pode fazer push? | Pode mergear? |
|---|---|---|---|
| **CEO AI** | Define OKRs, aprova roadmap, decide bloqueios de negócio | ❌ | ❌ |
| **COO AI** | Coordena operações diárias, distribui tasks entre diretores | ❌ | ❌ |
| **PMO AI** | Gerencia backlog (Issues GitHub), define prioridades e dependências, cria issues | ❌ | ❌ |
| **CTO AI** | Define stack, aprova arquitetura, revisa PRs críticos, decide débito técnico | ❌ | ✅ (via admin) |
| **Arquiteto AI** | Desenha soluções técnicas, define interfaces entre módulos, documenta ADRs | ❌ | ❌ |
| **Security AI** | Audita código para segredos expostos, LGPD, OWASP Top 10, valida .gitignore | ❌ | ❌ (bloqueia PR) |
| **QA AI** | Executa smoke tests, valida endpoints, confere critérios de aceite | ❌ | ❌ (aprova/bloqueia PR) |
| **Release Manager AI** | Cria branch, abre PR, faz merge após aprovações, executa rollback | ✅ (branches) | ✅ (após QA+Security) |
| **Observability AI** | Monitora logs, métricas, alertas; dispara re-execução se falhar | ❌ | ❌ |
| **Executores OpenRouter** | Implementam código conforme spec do Arquiteto; operam em branches | ✅ (branches) | ❌ |

---

## 3. Fluxo Git Obrigatório

```
Issue aberta (PMO)
       │
       ▼
Branch criada: feat/issue-{N}-{slug}   ← Release Manager ou Executor
       │
       ▼
Commits na branch                       ← Executores OpenRouter
       │
       ▼
Push origin/feat/...                    ← Executor
       │
       ▼
PR aberto → base: main                  ← Release Manager
       │
       ├── Security AI review           ← bloqueia se encontrar segredo/vuln
       ├── QA AI review                 ← bloqueia se smoke test falhar
       └── CTO/Arquiteto review         ← bloqueia se violar arquitetura
       │
       ▼ (todas aprovações OK)
Merge → main                            ← Release Manager
       │
       ▼
Webhook → deploy automático → servidor  ← deploy-webhook.php
       │
       ▼
Smoke test pós-deploy                   ← Observability AI
       │
       ├── OK → fecha issue, loga sucesso
       └── FAIL → rollback automático (revert commit + merge anterior)
```

### Regras absolutas de Git

- **NUNCA** push direto para `main` — qualquer tentativa é bloqueada pelo hook `pre-push`
- **NUNCA** `--force` ou `--no-verify` sem autorização explícita do CTO
- Branch naming: `feat/`, `fix/`, `hotfix/`, `chore/`, `docs/`
- Commit messages: `type(scope): descrição` em português ou inglês
- Commit messages em português: `feat:`, `fix:`, `chore:`, `docs:`
- `node_modules/`, `.env`, `logs/`, `*.sql` são permanentemente ignorados (`.gitignore`)

---

## 4. Fluxo de Execução por Issues

```
1. CEO AI abre issue ou aprova issue do PMO
       │
2. PMO AI prioriza no backlog, adiciona labels e dependências
       │
3. CTO AI ou Arquiteto AI define spec técnica (comentário na issue)
       │
4. Security AI valida a spec (impacto em segredos, LGPD, permissões)
       │
5. Release Manager cria branch feat/issue-{N}-{slug}
       │
6. Executor OpenRouter implementa seguindo a spec
       │
7. Executor faz push e abre PR via Release Manager
       │
8. QA AI executa smoke tests e valida critérios de aceite
       │
9. Security AI re-audita o diff do PR
       │
10. Release Manager faz merge se todas aprovações OK
        │
11. Observability AI valida pós-deploy (5 minutos após merge)
        │
2. PMO AI prioriza no backlog, adiciona labels e dependências
3. CTO AI ou Arquiteto AI define spec técnica (comentário na issue)
4. Security AI valida a spec (impacto em segredos, LGPD, permissões)
5. Release Manager cria branch feat/issue-{N}-{slug}
6. Executor OpenRouter implementa seguindo a spec
7. Executor faz push e abre PR via Release Manager
8. QA AI executa smoke tests e valida critérios de aceite
9. Security AI re-audita o diff do PR
10. Release Manager faz merge se todas aprovações OK
11. Observability AI valida pós-deploy (5 minutos após merge)
12. PMO AI fecha a issue e atualiza backlog
```

---

## 5. Guardião de Preço

### Regra fundamental
> **LLM nunca decide preço.** Qualquer valor monetário exibido ao cliente é calculado por código determinístico local, nunca por inferência de modelo AI.

### O que é bloqueado automaticamente

- Qualquer commit que modifique `price`, `preco`, `valor`, `desconto`, `cupom`, `markup`, `margem` em arquivos de catálogo sem passar pelo Guardião
- Scripts que calculem preço final usando output de LLM
- Alterações em `config/constants.php` sem revisão do CTO

### Como funciona o Guardião (`scripts/git_autonomous_agent.py`)

```python
# Campos protegidos — alteração bloqueia o commit
PRICE_FIELDS = ['price', 'preco', 'valor', 'sale_price', 'markup', 'margem', 'desconto']

# Fluxo:
# 1. Agente propõe alteração de catálogo
# 2. Guardião verifica se algum campo protegido mudou
# 3. Se sim → bloqueia, loga em logs/guardian.log, notifica CTO
# 4. Liberação só com aprovação humana explícita
```

### Exceções permitidas

- Sync via API Tiny/Olist autenticada (fonte oficial de preços)
- Correção de preço zero para preço da API (nunca o contrário)
- Ajuste de moeda/formatação sem alterar valor numérico

---

## 6. Regras de QA, Segurança, Deploy e Rollback

### QA (antes de todo merge)

```
✅ Endpoints críticos respondem 2xx:
   - GET /                          → 200
   - GET /api/catalog/products.php  → 200 + JSON válido
   - GET /api/health.php            → 200
   - GET /api/agent/autonomous-report.php → 200

✅ Nenhum produto com preço negativo no catálogo
✅ Nenhum segredo exposto em resposta HTTP
✅ .htaccess válido (não quebrou rotas existentes)
✅ Logs de erro PHP não aumentaram após deploy

✅ Nenhum produto com preço negativo
✅ Nenhum segredo exposto em resposta HTTP
✅ PHP sem erros de sintaxe (php -l arquivo.php)
```

### Security (antes de todo merge)

```
✅ SECRET_PATTERNS não detectado no diff:
   - API keys, tokens, passwords hardcoded
   - URLs com credenciais embutidas
   - Arquivos .env acidentalmente adicionados

✅ Arquivos protegidos intactos:
   - .github/workflows/deploy.yml
   - config/constants.php
   - storage/commerce_signals.json
   - deploy-webhook.php

✅ Permissões de arquivo corretas (sem 777)
✅ Sem SQL injection em novos endpoints
✅ Sem XSS em output de dados do usuário
✅ Nenhum API key, token ou password hardcoded no diff
✅ Arquivos protegidos intactos (deploy.yml, config/constants.php)
✅ Sem SQL injection em novos endpoints
✅ .env não commitado
```

### Deploy

```
Trigger:    push em main → GitHub webhook → deploy-webhook.php
Estratégia: ZIP download via GitHub API + extração no webroot
Skip list:  .git, .github, .claude, .env, logs/, deploy-webhook.php
Timeout:    120 segundos
Log:        logs/deploy-webhook.log
```

### Rollback

```
Automático: Observability AI detecta falha pós-deploy →
            git revert HEAD~1 em branch hotfix/rollback-{ts} →
            PR aberto → merge imediato pelo Release Manager

Manual:     gh pr create --title "hotfix: rollback deploy {sha}"
            gh pr merge --admin
```

---

## 7. Critérios de Aceite para Qualquer PR

Todo PR deve satisfazer **todos** os critérios antes do merge:

| # | Critério | Verificado por |
|---|---|---|
| 1 | Branch não é `main` | Git hook |
| 2 | Smoke tests passam (ver seção QA) | QA AI |
| 3 | Nenhum segredo no diff | Security AI |
| 4 | Arquivos protegidos intactos | Security AI |
| 5 | Preços não alterados por LLM | Guardião de Preço |
| 6 | `.env` não commitado | `.gitignore` + Security |
| 7 | PR tem descrição com: o que faz, como testar, issue relacionada | PMO AI |
| 8 | Sem `console.log` / `var_dump` / `dd()` em produção | QA AI |
| 9 | Novos endpoints documentados no PR body | Arquiteto AI |
| 10 | Deploy smoke test passou (5min após merge) | Observability AI |

---

## 8. Comandos Padrão — CEO → CTO → OpenRouter → QA

### Iniciar nova feature

```bash
# PMO cria issue
gh issue create \
  --repo fredmourao-ai/site-shopvivaliz \
  --title "[FEATURE] Descrição da feature" \
  --body "Spec técnica: ...\nCritérios de aceite: ...\nPrioridade: high/normal/low"

# Release Manager cria branch
git checkout main && git pull
git checkout -b feat/issue-{N}-{slug}

# Executor implementa e commita
git add {arquivos}
git commit -m "feat(scope): descrição"
git push origin feat/issue-{N}-{slug}

# Release Manager abre PR
gh pr create \
  --base main \
  --title "feat: descrição (#N)" \
  --body "Closes #N\n\n## O que faz\n...\n\n## Como testar\n..."
```

### QA smoke test manual

```bash
# Verificar endpoints após deploy
curl -sf https://dev.shopvivaliz.com.br/ -o /dev/null && echo "home: OK"
curl -sf https://dev.shopvivaliz.com.br/api/health.php | python3 -c "import sys,json; d=json.load(sys.stdin); print('health:', d.get('status','?'))"
curl -sf https://dev.shopvivaliz.com.br/api/agent/autonomous-report.php | python3 -c "import sys,json; d=json.load(sys.stdin); print('catalog:', d['catalog']['total'], 'produtos')"
curl -sf "https://dev.shopvivaliz.com.br/api/orchestrator/status.php?secret=$CRON_SECRET" | python3 -c "import sys,json; d=json.load(sys.stdin); print('queue:', d['queue']['counts'])"
```

### Rollback de emergência

```bash
# Identificar commit anterior estável
git log --oneline origin/main | head -5

# Criar branch de rollback
git checkout -b hotfix/rollback-$(date +%s) origin/main
git revert HEAD --no-edit
git push origin hotfix/rollback-$(date +%s)

# PR com merge imediato
gh pr create --base main --title "hotfix: rollback emergência" --body "Deploy anterior causou falha. Revertendo."
gh pr merge --admin --merge
```

### Orquestrador (agentes 24/7)

```bash
# Status do orquestrador
curl -s "https://dev.shopvivaliz.com.br/api/orchestrator/status.php?secret=$CRON_SECRET"

# Diretor toma decisões
curl -s "https://dev.shopvivaliz.com.br/api/orchestrator/director.php?secret=$CRON_SECRET"

# Forçar execução imediata do scheduler
curl -s "https://dev.shopvivaliz.com.br/api/orchestrator/scheduler.php?secret=$CRON_SECRET"

# Painel admin visual
open https://dev.shopvivaliz.com.br/admin/orchestrator.php
```

---

## Apêndice: Arquivos Protegidos (nunca alterar sem CTO)

```
.github/workflows/deploy.yml       # CI/CD pipeline
.github/AGENTS.md                  # este documento
config/constants.php               # configurações globais
deploy-webhook.php                 # receptor de webhook
storage/commerce_signals.json      # sinais de mercado
scripts/git_autonomous_agent.py    # Guardião de Preço + Secret Scanner
logs/                              # nunca versionado
.env                               # nunca versionado
```

---

*Documento mantido pelo CTO AI. Alterações requerem PR com revisão obrigatória do CTO e Security AI.*
scripts/git_autonomous_agent.py    # Guardião de Preço + Secret Scanner
```

*Documento mantido pelo CTO AI. Alterações requerem PR com revisão do CTO e Security AI.*
