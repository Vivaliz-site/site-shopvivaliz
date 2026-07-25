# AUDITORIA FASE A — Proposta Técnica de Migração
## SHOPVIVALIZ — Migração para Arquitetura de Releases Imutáveis

**Data:** 2026-07-25  
**Status:** 🔴 CRÍTICO — Bloqueador de produção confirmado  

---

## CAUSA RAIZ CONFIRMADA

### Problema Primário
`git-auto-sync.py` (linha 141) executa `git merge --ff-only origin/main` na árvore viva da aplicação:
```python
run(["git", "merge", "--ff-only", f"origin/{branch}"], check=True)
```

**Cenário de Falha:**
1. VM Oracle detecta novo commit em `origin/main`
2. Tenta fazer `git merge --ff-only`
3. Detecta histórico divergente ou estado sujo
4. Falha com: `fatal: refusing to merge unrelated histories`
5. Erro registrado em `/var/log/git-auto-sync.log`
6. **Produção fica presa** esperando merge bem-sucedido

### Problema Secundário
O script foi concebido para sincronizar a árvore **viva** (servida pelo Apache) diretamente. Qualquer arquivo modificado em runtime (`.env`, uploads, cache) bloqueia o sync. Hoje bloqueia em `sync-cache-to-admin.php` conforme relatado no prompt.

### Problema Estrutural
**91 workflows em `.github/workflows/`** — cada agente autônomo criou o seu sem consolidar:
- `autonomous-*.yml` (múltiplas)
- `24-7-*.yml` (múltiplas)
- `ai-*.yml` (múltiplas)
- `sync-*.yml` (múltiplas)
- `auto-*.yml` (múltiplas)
- Workflows com nomes sobrepostos

**Impacto:** Ambiguidade sobre qual workflow é o "oficial" para deploy/validação.

---

## ARQUITETURA ATUAL

```
/home/ubuntu/site-shopvivaliz/
├── .git/
├── código-fonte (versionado)
├── .env (runtime, versionado = RISCO)
├── uploads/ (runtime, não-versionado)
├── logs/ (runtime, não-versionado)
├── cache/ (runtime, não-versionado)
├── sync-cache-to-admin.php (MODIFICADO por runtime)
└── Servido diretamente pelo Apache
    (nenhuma separation entre código e dados)
```

**Problemas:**
- ✗ Árvore viva = código + dados + cache
- ✗ Deploy = merge em árvore viva
- ✗ Arquivo de código modificado por runtime (sync-cache-to-admin.php)
- ✗ Nenhuma release imutável
- ✗ Sem rollback rápido
- ✗ Sem lock de concorrência confirmado
- ✗ Sem health check pós-deploy

---

## ARQUITETURA PROPOSTA

```
/home/ubuntu/shopvivaliz-deploy/
├── repo/
│   ├── .git/ (clone limpo, apenas leitura)
│   ├── scripts/
│   │   ├── deploy-production.sh
│   │   ├── rollback-production.sh
│   │   ├── validate-release.sh
│   │   └── health-check.sh
│   └── (código-fonte)
├── releases/
│   ├── 20260725-143500-2489d8d9/ (release-1)
│   ├── 20260725-130000-14fd5ed5/ (release-2)
│   └── ...
├── shared/
│   ├── .env (symlink ou copy seguro)
│   ├── config/
│   ├── uploads/
│   ├── logs/
│   ├── cache/
│   ├── sessions/
│   ├── sync-cache-to-admin.php (se for gerado, gera aqui)
│   └── dados runtime
└── current → releases/20260725-143500-2489d8d9/ (symlink atômico)
```

**Vantagens:**
- ✓ Releases imutáveis e recuperáveis
- ✓ Clone sempre limpo
- ✓ Deploy pull-based, não push
- ✓ Rollback atômico
- ✓ Sem merge na árvore viva
- ✓ Runtime separado de código
- ✓ Auditable, reversível

---

## PONTOS AINDA NÃO CONFIRMADOS (Requer SSH à VM)

1. **Localização exata do erro** `fatal: refusing to merge unrelated histories`
   - Qual `.git` tem histórico divergente?
   - Há múltiplos `.git` ou worktrees?

2. **Conteúdo real de `sync-cache-to-admin.php`**
   - É código puro?
   - É cache gerado?
   - É mistura de código + dados?

3. **Quem modifica `sync-cache-to-admin.php` em produção?**
   - Algum cron?
   - Algum webhook?
   - Algum script web?

4. **Estado das branches oldadas** (backup/*, server-backup/*, temp-deploy)
   - Podem ser removidas com segurança?

5. **Espaço em disco** para backup de migração

6. **Apache DocumentRoot** exato
   - `/home/ubuntu/site-shopvivaliz/` ou `/home/ubuntu/site-shopvivaliz/public/`?

---

## ARQUIVOS A ALTERAR (Fase Local VS Code)

### Criar
- `AGENTS.md` — Instruções permanentes para todos os agentes
- `docs/DEPLOY-ORACLE.md` — Documentação completa de deploy
- `scripts/deploy-production.sh` — Script de deploy imutável
- `scripts/rollback-production.sh` — Script de rollback
- `scripts/validate-release.sh` — Validação pré-deploy
- `scripts/health-check.sh` — Health check pós-deploy
- `.gitattributes` — Força LF em scripts Linux
- `.github/pull_request_template.md` — Checklist de PR
- `.github/CODEOWNERS` — Proprietários críticos

### Modificar
- `.gitignore` — Apenas arquivos realmente runtime, após comprovação
- `git-auto-sync.py` — Remover ou simplificar drasticamente
- `.github/workflows/shopvivaliz-qa.yml` — Manter, é o único validador
- `.github/workflows/deploy.yml` — Marcar como "legado-hostgator" ou remover
- `README.md` — Adicionar seção sobre releases imutáveis

### Remover Após Migração Validada
- Workflows antigos redundantes (após consolidação)
- `auto-sync-triad.py` (se redundante)
- Cron antigo em produção

---

## IMPACTO ESTIMADO

| Área | Impacto | Severidade |
|------|--------|-----------|
| **VS Code (Local)** | Novos scripts, docs, AGENTS.md | MÉDIO — local-only |
| **GitHub** | Consolidação workflows, branch protection | MÉDIO — pós-validação |
| **VM Oracle** | Nova estrutura, novo cron, reloadApache | 🔴 ALTO — em produção |
| **Downtime** | ~2-5 min para testar atual → novo | ACEITÁVEL se SSH |

---

## DADOS RUNTIME A PRESERVAR

Antes da migração, **MUST** identificar:

```
.env (credenciais — CRÍTICO)
config/runtime-secrets.php (se existir)
uploads/ (usuário uploads — CRÍTICO)
logs/ (histórico — PRESERVE)
cache/ (regenerável — OK perder)
sessions/ (usuário sessions — PRESERVE se persist)
storage/ (dados Olist/Tiny/Mercado Pago — CRÍTICO)
sync-cache-to-admin.php (TBD — precisa inspeção)
```

---

## RISCOS CONFIRMADOS

🔴 **CRÍTICO**
1. `sync-cache-to-admin.php` está **modificado** e bloqueia deploy atual
   - Ação: Investigar raiz, separar código de dados
2. Histórico Git divergente em produção
   - Ação: Clone novo, não tentar reconciliar
3. 91 workflows criando ambiguidade
   - Ação: Consolidar pós-migração

🟠 **ALTO**
1. Nenhum lock confirmado durante deploy
   - Ação: Implementar flock explícito
2. Sem health check automatizado pós-deploy
   - Ação: Criar script obrigatório
3. Sem rollback documentado/testado
   - Ação: Teste real antes de produção

🟡 **MÉDIO**
1. Backups pré-migração = responsabilidade manual
   - Ação: Criar e validar backup SSD

---

## PRÓXIMAS FASES (Sequência)

**FASE B — Diagnóstico SSH à VM**
- [ ] Confirmar localização de `.git` divergente
- [ ] Inspecionar `sync-cache-to-admin.php` conteúdo real
- [ ] Verificar espaço disco
- [ ] Auditar branches antigas

**FASE C — Branch Local (VS Code)**
- [ ] Criar `fix/production-release-deploy`
- [ ] Não trabalhar em main

**FASE D-G — Código e Documentação (VS Code)**
- [ ] AGENTS.md
- [ ] DEPLOY-ORACLE.md
- [ ] Scripts de deploy
- [ ] .gitignore refinado
- [ ] Workflows consolidados

**FASE H-K — Testes Locais**
- [ ] lint PHP
- [ ] Validar scripts shell
- [ ] Buscar secrets

**FASE L-Q — Produção (SSH)**
- [ ] Backup pré-migração
- [ ] Nova estrutura
- [ ] Primeiro deploy
- [ ] Testes rollback
- [ ] Ativar cron novo
- [ ] Desativar cron antigo

---

## CHECKPOINT: ANTES DE PROSSEGUIR

✅ **Este documento está pronto.**  
✅ **Auditoria local completa.**  
🔄 **PAUSA: Aguardando confirmação para SSH à VM (FASE B).**

**Próximo passo do usuário:** Confirmar se devo:
1. ✅ Continuar com FASE B (SSH à VM — requer confirmação explícita)
2. ✅ Começar FASE C-G (VS Code — branch, docs, scripts — seguro, local-only)
3. ❌ Parar por ora

---

**Responsável:** Claude Code Autonomous  
**Timestamp:** 2026-07-25T00:00:00Z (sessão atual)
