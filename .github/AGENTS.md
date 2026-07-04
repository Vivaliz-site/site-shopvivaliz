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
- Commit messages em português: `feat:`, `fix:`, `chore:`, `docs:`
- `node_modules/`, `.env`, `logs/`, `*.sql` são permanentemente ignorados (`.gitignore`)

---

## 4. Fluxo de Execução por Issues

```
1. CEO AI abre issue ou aprova issue do PMO
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

> **LLM nunca decide preço.** Qualquer valor monetário exibido ao cliente é calculado por código determinístico local, nunca por inferência de modelo AI.

**Bloqueado automaticamente (requer aprovação humana):**
- Preço de venda ou preço promocional
- Descontos ou cupons que afetam o preço final
- Margem, markup ou regras de precificação
- Sincronização de preço entre site, marketplaces, Olist, Tiny, ML, Shopee

**Permitido autonomamente:**
- Desenvolvimento, QA, documentação
- Deploy, rollback, integrações
- Sincronizações de catálogo, imagens, anúncios (sem alterar preço)
- Monitoramento, observabilidade e auto-recuperação

---

## 6. Regras de QA, Segurança e Deploy

### QA (antes de todo merge)

```
✅ Endpoints críticos respondem 2xx:
   - GET /                          → 200
   - GET /api/catalog/products.php  → 200 + JSON válido
   - GET /api/health.php            → 200

✅ Nenhum produto com preço negativo
✅ Nenhum segredo exposto em resposta HTTP
✅ PHP sem erros de sintaxe (php -l arquivo.php)
```

### Security (antes de todo merge)

```
✅ Nenhum API key, token ou password hardcoded no diff
✅ Arquivos protegidos intactos (deploy.yml, config/constants.php)
✅ Sem SQL injection em novos endpoints
✅ .env não commitado
```

### Deploy

```
Trigger:    push em main → GitHub Actions → FTP HostGator
Log:        automation/eha/reports/last_ci_run.json
Dashboard:  https://dev.shopvivaliz.com.br/claude
```

### Rollback

```
Automático: Observability detecta falha → revert + PR hotfix → merge imediato
Manual:     git revert HEAD~1 → branch hotfix/rollback-{ts} → merge admin
```

---

## 7. Critérios de Aceite para Todo PR

| # | Critério | Verificado por |
|---|---|---|
| 1 | Branch não é `main` | Git hook |
| 2 | Smoke tests passam | QA AI |
| 3 | Nenhum segredo no diff | Security AI |
| 4 | Preços não alterados por LLM | Guardião de Preço |
| 5 | `.env` não commitado | `.gitignore` + Security |
| 6 | PR tem descrição completa | PMO AI |
| 7 | Sem `var_dump` / `dd()` em produção | QA AI |
| 8 | Novos endpoints documentados | Arquiteto AI |

---

## 8. Secrets e Segurança

- **Nunca** commitar `.env`, tokens, API keys, senhas ou cookies
- Credenciais somente via GitHub Secrets ou `.env` no servidor (fora do repo)
- Mascarar secrets com `***` em logs quando necessário

---

## 9. Padrão de Modelo OpenAI (custo mínimo)

```env
OPENAI_MODEL=gpt-4o-mini
OPENAI_REASONING_EFFORT=minimal
```

Usar modelo mais caro apenas quando qualidade superior for justificada.

---

## 10. Contato para Bloqueios

Escalar para o responsável humano quando:
- Credencial ausente ou expirada
- Decisão de preço pendente
- Permissão de servidor necessária

**Responsável:** fredmourao@gmail.com  
**Repositório:** https://github.com/fredmourao-ai/site-shopvivaliz  
**Dashboard:** https://dev.shopvivaliz.com.br/claude

---

## Apêndice: Arquivos Protegidos (nunca alterar sem CTO)

```
.github/workflows/deploy.yml       # CI/CD pipeline
.github/AGENTS.md                  # este documento
config/constants.php               # configurações globais
deploy-webhook.php                 # receptor de webhook
scripts/git_autonomous_agent.py    # Guardião de Preço + Secret Scanner
```

*Documento mantido pelo CTO AI. Alterações requerem PR com revisão do CTO e Security AI.*
