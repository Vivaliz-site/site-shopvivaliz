<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist transacional obrigatorio: todos os agentes e todas as IAs

A cada tarefa material, consultar e atualizar o checklist duravel ANTES de agir, DEPOIS de cada etapa e ANTES de escolher a proxima acao. Esta regra vale para ChatGPT/Gepeto, Codex, Claude, Gemini, Copilot, workers e subagentes. A sessao ou o controlador nao substituem a responsabilidade de cada agente em executar a proxima etapa permitida.

Usar o registro canonico `scripts/agent_task_state.py`: iniciar o checkpoint com identidade/autorizacao reais; `plan-checklist --task ID --step "..." --step "..."` antes de qualquer progresso; `next-step --task ID` para consultar o item aberto; `check-step --task ID --step-id N --evidence "prova concreta" --verification "validacao independente"` ao completar CADA item; `add-checklist-step` para nova descoberta sem apagar etapas ja comprovadas. Somente passar ao proximo item depois de verificar a conclusao do anterior. `ready` e `complete` nao substituem testes finais, E2E/UI, gates, deploy e merge.

Checklists iniciados nao podem ser sobrescritos para apagar pendencias, pular etapas, inventar provas, ou transformar bloqueios em conclusoes. Nos novos checkpoints do runtime canonico a falta de plano ou de qualquer item comprovado bloqueia `READY_TO_COMPLETE` e `CONCLUIDO`. Checkpoints legados exigem migracao explicita, preservando historico, leases, ownership, next_action e prova existente; nao criar tarefa duplicada. Falhas de ferramenta exigem rota alternativa autorizada e estado aberto, nao falsificacao de check. Todos os repositorios/agentes devem adotar o mesmo contrato ou um mecanismo equivalente com testes negativos; documento presente sozinho nao certifica adesao operacional.

Protecoes do GitHub, MFA, RBAC, segredos, gates comerciais e aprovacoes humanas continuam vigentes. Registro de check nao prova, por si, o comportamento real: confirmar os resultados nos sistemas alvo e nos certificadores independentes aplicaveis.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

## Regra universal de continuidade e esgotamento de rotas — todos os agentes e repositorios (2026-10-31)

**Obrigatoria para toda execucao e todo agente, independentemente do fornecedor, modelo ou interface:** ChatGPT, Codex, Claude, Gemini, agentes autonomos, bots, workers, scripts, GitHub Actions, ferramentas MCP e futuros agentes. Aplica-se a todos os repositorios e organizacoes GitHub sob gestao da ShopVivaliz, nao apenas a este repositorio.

**Nao abandonar tarefas por um bloqueio tecnico isolado.** Investigar a causa raiz, corrigir o que for seguro e autorizado, testar novamente e inventariar sistematicamente todas as rotas REALMENTE provisionadas e independentemente autorizadas antes de alegar bloqueio: MCP/Remote Control, conectores, Cloudflare/cloudflared e tuneis, GitHub CLI/API com identidade correta e credenciais protegidas, SSH privado/Tailscale, RDC 1/2, navegadores de agentes no host permitido, VMs ativas, runners/Actions, OCI, Superpowers quando disponivel e outros recursos identificados na documentacao vigente. Nao assumir disponibilidade, permissao ou sucesso sem teste e evidencia. Nao expor segredos nem criar credenciais paralelas.

Para cada rota registrar disponibilidade, identidade, escopo de permissao, lease/fencing quando aplicavel, tentativa, resultado, causa da falha, reparo e proxima alternativa. Nao repetir indefinidamente a mesma tentativa. Prosseguir nas etapas independentes, preservar checkpoints duraveis e retomada automatica autorizada. A tarefa so termina com correcao, prevencao, testes, validacao runtime/E2E real e merge apos gates quando aplicavel, ou bloqueio externo comprovado apos esgotamento de todas as rotas legitimas. Nenhum status RUNNING antigo comprova execucao ativa.

**Limites absolutos:** nao contornar MFA, CAPTCHA, RBAC, 401/403, recusa expressa, branch protection, aprovacoes, leases, isolamento de hosts ou politicas do provedor. Rota alternativa nunca pode ser usada para burlar uma negativa. Manter pendencias abertas com evidencia e proxima acao quando existir impedimento legitimo.

**Propagacao:** manter esta regra nos arquivos de instrucoes de agentes de cada repositorio ativo (AGENTS.md, CLAUDE.md, GEMINI.md, instrucoes Codex e equivalentes existentes), respeitando a governanca local; verificar aplicacao por inventario e testes, nao por presuncao.

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Verificacao posterior, prevalente (2026-10-29):** o MCP central do backend foi corrigido e implantado (PRs #3391/#3392): `hosts_list` agora divulga **somente backend, Fred-Win e KOCEPSV**; `shopvivaliz-free-a1` foi excluida e rejeitada. Marina segue fora da allowlist MCP central; seu MCP proprio OpenAI esta inativo, embora haja RDC direto, SSH privado verificado e tunel SSH do **MEI** ativo para Marina (HTTP 200). O PR #3395 bloqueou 20 jobs legados na origem, sem certificar as funcoes substitutas. O controlador 24x7 ainda exige reconciliacao sob lease valido e o runner `mei-ci-a1` permanece sem registro. **Toda afirmacao mais antiga de "MCP central ainda inclui a origem" neste arquivo e um snapshot anterior, agora superado.** Ver `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Rotas operacionais atualizadas em 2026-10-29:** para qualquer agente (Claude, Codex, Gemini, GPT, IA híbrida, Copilot, Windsurf, browser/QA e subagentes), a fonte válida é `docs/knowledge/current-topology-and-access-2026-10-29.md` e `docs/knowledge/host-access.md` no `main`, com SHA, identidade, permissão e runbook do projeto confirmados. Web/deploy/MySQL/workers ficam na **Marina** (`marina-a1-4c24g`, OCI ESPOSA; RDC direto já comprovado); backend/browser/MCP central ficam em `always-free-arm-1787907847-26` (OCI PRINCIPAL). A VM origem `shopvivaliz-free-a1` foi excluída e nunca pode ser acessada ou recriada. O MCP central ainda a anuncia erroneamente e não executa Marina; o MCP independente Marina está inativo/não registrado. Não reutilizar aliases SSH ou receitas de quatro hosts, nem presumir RDC 2, sudo ou túnel pelo status de cloudflared. Respeitar a política central imutável, auditoria V5 com UI real, leases/fencing, segredo protegido e correção/teste/validação após cada erro. Em rotina persistente, respeitar prioridade de IA gratuita e limites de custo.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

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
- **NUNCA** trate commit, push de branch ou PR aberta como conclusão. A rodada só termina após checks, merge, validação pós-merge, working tree limpa e confirmação de que não existe PR aberta/draft da própria tarefa.
- Se CI, review ou conflito falhar e houver correção executável, corrija e continue; não devolva o trabalho ao usuário no meio do fluxo.
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
curl -sf https://shopvivaliz.com.br/ -o /dev/null && echo "home: OK"
curl -sf https://shopvivaliz.com.br/api/health.php | python3 -c "import sys,json; d=json.load(sys.stdin); print('health:', d.get('status','?'))"
curl -sf https://shopvivaliz.com.br/api/agent/autonomous-report.php | python3 -c "import sys,json; d=json.load(sys.stdin); print('catalog:', d['catalog']['total'], 'produtos')"
curl -sf "https://shopvivaliz.com.br/api/orchestrator/status.php?secret=$CRON_SECRET" | python3 -c "import sys,json; d=json.load(sys.stdin); print('queue:', d['queue']['counts'])"
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
curl -s "https://shopvivaliz.com.br/api/orchestrator/status.php?secret=$CRON_SECRET"

# Diretor toma decisões
curl -s "https://shopvivaliz.com.br/api/orchestrator/director.php?secret=$CRON_SECRET"

# Forçar execução imediata do scheduler
curl -s "https://shopvivaliz.com.br/api/orchestrator/scheduler.php?secret=$CRON_SECRET"

# Painel admin visual
open https://shopvivaliz.com.br/admin/orchestrator.php
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
