# ShopVivaliz - Sistema Integrado de Automação

> Última atualização: 2026-07-09 (revisado para bater com o estado real do repo — ver CHANGELOG.md)  
> Responsável: fredmourao-ai + Claude Code Autonomous  
> Status: ✅ Produção - deploy real via VM Oracle (não FTP, ver abaixo)  
> Repositório real: **https://github.com/Vivaliz-site/site-shopvivaliz** (não `fredmourao-ai/site-shopvivaliz`)

> ⚠️ **LEIA `docs/MEMORIA-AGENTES.md` ANTES DE COMEÇAR.** Múltiplos agentes autônomos
> diferentes (Claude, GPT, Gemini) trabalham neste repo em sessões isoladas sem memória
> compartilhada — esse arquivo é o único lugar combinado onde erros e descobertas não
> óbvias já feitas ficam registrados, pra ninguém redescobrir o mesmo bug do zero. Ao
> final da sua sessão, se você aprendeu algo não-óbvio, adicione uma entrada lá.

---

## 📊 Visão Geral do Sistema

ShopVivaliz é um **e-commerce de alto rendimento** com automação de:
- **Deploy:** VM Oracle Cloud (`dev.shopvivaliz.com.br`, IP `137.131.156.17`) roda um cron
  (`git-auto-sync.py`, `*/30 * * * *`) que faz `git fetch` + `reset --hard` na `main` — é essa VM
  que serve o site diretamente, não FTP/HostGator.
- **Validação:** QA lint dispara em push/PR (`shopvivaliz-qa.yml`)
- **Execução de Tarefas:** Fila autônoma (`tasks-queue.json`), múltiplos workflows agendados
- **Agentes IA:** múltiplos agentes autônomos (Claude Code, e outros) commitam direto no repo —
  ver nota de risco abaixo

### 🏗️ Arquitetura Real de Deploy

```
┌─────────────────────────────────────────────────────────────┐
│                  GitHub (Fonte de Verdade)                  │
│  main branch — sem branch protection forte observada         │
└────────────────────┬────────────────────────────────────────┘
                     │  cron pull a cada 30min (git-auto-sync.py)
                     ▼
           ┌─────────────────────┐
           │  VM Oracle Cloud     │
           │  137.131.156.17      │
           │  dev.shopvivaliz.*   │  ← serve o site diretamente
           │  (Apache + PHP)      │     (nao ha FTP/HostGator ativo)
           └─────────────────────┘
```

**FTP/HostGator (`deploy.yml`, `auto-ftp-deploy.yml`) está desativado** — só roda via
`workflow_dispatch` manual, não em push. O comentário no próprio `deploy.yml` confirma: "a producao
real e a VM Oracle... nao o HostGator".

⚠️ **Risco conhecido:** o repositório tem **59 workflows** em `.github/workflows/` (não 4), muitos
com nomes sobrepostos (`autonomous-cycle.yml`, `autonomous-orchestrator.yml`,
`autonomous-proactive.yml`, `ci-autonomo-continuo.yml`, etc.) — sinal de que múltiplos agentes
diferentes já criaram automações redundantes sem consolidar. Isso já causou bugs reais em produção
(ver `CHANGELOG.md`: CSS wildcard quebrando a home, footer com dados inventados). Antes de criar um
novo workflow, verifique se um já não faz a mesma coisa.

---

## 🔄 Fluxo de Trabalho Dia-a-dia

### Ciclo Padrão de Desenvolvimento

```
1. Modificação Local (seu editor)
   ├─ Editar arquivo em C:\Users\FRED\site-shopvivaliz\
   └─ Testes locais se possível

2. Commit e Push
   └─ git add .
   └─ git commit -m "feat: descrição clara"
   └─ git push origin main

3. GitHub Dispara Pipeline Automática
   ├─ [1] QA Lint (5 min) - Valida PHP/JS
   │   └─ Se falhar: notifica, não deploy
   ├─ [2] Auto-validation (cron 30 min)
   │   └─ Se encontra issues: auto-fix + commit
   ├─ [3] Deploy (push) → FTP HostGator
   │   └─ Sincroniza código em produção
   └─ [4] Health Check (pós-deploy)
       └─ Testa endpoints críticos

4. Execução de Tarefas (cron 30 min)
   ├─ Lê fila (tasks-queue.json)
   ├─ Gemini analisa
   ├─ Claude implementa
   ├─ GPT revisa
   └─ Auto-commit resultado

5. Monitoramento Contínuo
   ├─ Logs: /logs/
   ├─ Status: /admin/monitor/
   └─ Alertas: GitHub Actions
```

### Exemplos Prácticos

**Adicionar feature simples:**
```bash
cd C:\Users\FRED\site-shopvivaliz
# editar arquivo
git add .
git commit -m "feat: nova feature X"
git push origin main
# Deploy automático em 5-10 minutos ✓
```

**Resolver issue encontrada por validação:**
- Auto-validator detecta problema
- Cria PR automático com fix
- Você revisa e merge
- Deploy automático ocorre

**Adicionar tarefa para agentes:**
```json
// tasks-queue.json
{
  "task_id": "SEO-001",
  "action": "optimize_listing",
  "target": "produto_id_123",
  "priority": "high",
  "assigned_to": ["gemini", "claude"],
  "status": "pending"
}
```
Executor autônomo pega a cada 30 minutos.

---

## 🔐 Configuração de Secrets (GitHub)

### Secrets Obrigatórios para Deploy

Todos configurados em `Settings > Secrets and variables > Actions`:

| Secret | Descrição | Status |
|--------|-----------|--------|
| `FTP_SERVER` | Host HostGator | ✅ Configurado |
| `FTP_USERNAME` | Usuário FTP | ✅ Configurado |
| `FTP_PASSWORD` | Senha FTP | ✅ Configurado |
| `FTP_PORT` | Porta FTP (21 ou 2121) | ✅ Configurado |
| `FTP_REMOTE_DIR` | Path remoto (/public_html) | ✅ Configurado |

### Secrets Opcionais (para agentes IA)

| Secret | Uso | Status |
|--------|-----|--------|
| `ANTHROPIC_API_KEY` | Claude API | ✅ Configurado |
| `OPENAI_API_KEY` | ChatGPT API | ✅ Configurado |
| `GEMINI_API_KEY` | Google Gemini | ✅ Configurado |

---

## ⚙️ Workflows Principais (não é a lista completa — são 59 arquivos)

### 1️⃣ `shopvivaliz-qa.yml` - Validação na Admissão
- **Triggers:** Push para main, pull_request, workflow_dispatch
- **Ação:** Lint PHP, bloqueia selector CSS wildcard estrutural (`[class*=hero]` etc — ver
  CHANGELOG.md), smoke test ao vivo do footer/hero na home após push
- **Status:** ✅ **ATIVO** (corrigido em 2026-07-09 — antes só disparava via workflow_dispatch
  manual, nunca automaticamente, apesar do que este arquivo dizia)

### 2️⃣ `auto-validation-and-fix.yml` - Auto-Fix de Issues
- **Triggers:** Schedule a cada 30 min, push para main
- **Ação:** Analisa código, detecta issues, auto-commit de fixes
- **Status:** ✅ **ATIVO** — mas sem hooks de teste real; já introduziu regressões (ver CHANGELOG.md)

### 3️⃣ `deploy.yml` / `auto-ftp-deploy.yml` - Deploy FTP HostGator
- **Status:** ❌ **DESATIVADO** (só `workflow_dispatch` manual). A produção real roda na VM Oracle
  via cron `git-auto-sync.py`, não FTP. Mantido no repo só para caso o HostGator volte a ser usado.

### 4️⃣ `ai-autonomous-executor.yml` - Executor de Tarefas
- **Triggers:** Schedule a cada 30 min
- **Ação:** Lê `tasks-queue.json`, chama APIs de IA, implementa mudanças, auto-commit
- **Status:** ✅ **ATIVO**

### Deploy real (fora do GitHub Actions)
- Cron na VM Oracle (`/home/ubuntu/site-shopvivaliz/git-auto-sync.py`, `*/30 * * * *`): faz
  `git fetch` + `reset --hard origin/main`. É isso que efetivamente coloca código em produção.
- Para forçar deploy imediato sem esperar o cron: `ssh -i <chave> ubuntu@137.131.156.17
  "cd /home/ubuntu/site-shopvivaliz && python3 git-auto-sync.py"`

---

## 📁 Estrutura de Arquivos Críticos

```
site-shopvivaliz/
├── .github/
│   ├── workflows/
│   │   ├── shopvivaliz-qa.yml              ← QA Lint
│   │   ├── auto-validation-and-fix.yml     ← Auto-fix
│   │   ├── deploy.yml                      ← Deploy FTP
│   │   └── ai-autonomous-executor.yml      ← Executor
│   └── scripts/
│       ├── autonomous-validator.py         ← Validação
│       └── autonomous-executor.py          ← Executor
├── scripts/
│   ├── resolve_git_agent_conflict.ps1      ← Resolver conflitos
│   ├── install-git-auto-sync.ps1           ← Auto-sync setup
│   └── git_autonomous_agent.py             ← Agent de merge
├── tasks-queue.json                        ← Fila de tarefas
├── CLAUDE.md                               ← Este arquivo
├── CLAUDE-AUTONOMO.md                      ← Operações autônomas
└── logs/
    ├── validation-*.log                    ← Log de validação
    ├── deployment-*.log                    ← Log de deploy
    └── executor-*.log                      ← Log de tarefas
```

---

## 🚨 Troubleshooting

### Problema: Workflow não executa após push

**Causa possível:** Workflows desabilitados em Settings  
**Solução:**
```
1. GitHub > Settings > Actions > General
2. Selecionar: "All actions and reusable workflows"
3. Save
```

### Problema: Deploy falha com erro de autenticação FTP

**Causa possível:** Secrets FTP_* incorretos ou expirados  
**Solução:**
```bash
# Testar conexão FTP local
ftp -n <FTP_SERVER> <<EOF
user <FTP_USERNAME> <FTP_PASSWORD>
quit
EOF

# Se conectar, secret está OK
# Se falhar, atualizar secret no GitHub Settings
```

### Problema: Auto-validation cria conflitos recursivos

**Causa possível:** Multiple commits simultâneos  
**Solução:**
- Lock implementado em `auto-validation-and-fix.yml`
- Se persistir, delay de 30 seg entre commits
- Log em `/logs/validation-*.log`

### Problema: Tarefas autônomas não executam

**Causa possível:** `tasks-queue.json` com status inválido  
**Solução:**
```json
// Verificar que toda tarefa tem:
{
  "task_id": "ABC-001",
  "status": "pending",        // ← Deve ser 'pending'
  "assigned_to": ["gemini"],  // ← Agente válido
  "action": "valid_action"    // ← Ação registrada
}
```

---

## 🔗 Referências Rápidas

| Necessidade | Arquivo | Ação |
|------------|---------|------|
| Ver logs de validação | `/logs/validation-*.log` | `tail -f` |
| Ver logs de deploy | `/logs/deployment-*.log` | GitHub Actions UI |
| Adicionar tarefa IA | `tasks-queue.json` | Editar + push |
| Modificar pipeline | `.github/workflows/*.yml` | Editar + push |
| Resolver conflito merge | `scripts/resolve_git_agent_conflict.ps1` | Executar |
| Sincronizar ambientes | `git fetch && git pull origin main` | Bash/PowerShell |
| Integrar com Tiny/Olist ERP | `docs/TINY-ERP-API-V3.md` | Ler ANTES de mexer em `includes/tiny-order-push.php`, `daemon-sync-products.py`, `api/olist/*` |

---

## 🧠 Memória compartilhada entre agentes — `docs/MEMORIA-AGENTES.md`

Múltiplos agentes diferentes (Claude, GPT, Gemini) trabalham autonomamente neste repo,
cada um em sessões isoladas sem memória compartilhada entre si. Isso já causou o mesmo
bug de integração (ex: enum `situacao` do Tiny invertido) ser "descoberto" e corrigido
mais de uma vez, em sessões diferentes, sem que a segunda soubesse que a primeira já
tinha mapeado o problema.

**`docs/MEMORIA-AGENTES.md` é o único lugar combinado pra isso — leia antes de investigar
um bug que parece familiar ou integrar com um sistema externo (Tiny/Olist, Mercado Pago,
Melhor Envio, Mercado Livre).** Se você descobrir algo não-óbvio (campo de API com nome
diferente do esperado, enum contra-intuitivo, limite de taxa, comportamento assíncrono),
adicione uma entrada lá seguindo o formato descrito no topo do arquivo. Documentação
extensa (schema completo de uma API, por exemplo) vai num arquivo dedicado em `docs/`
(ex: `docs/TINY-ERP-API-V3.md`), com só um resumo e link em `MEMORIA-AGENTES.md`. O
objetivo é que cada agente que passar por aqui saia mais capaz que o anterior — não que
cada um recomece do zero.

---

## 🧠 Conhecimento acumulado (`docs/*.md`) — leia antes de reinventar

Múltiplos agentes diferentes (Claude, GPT, Gemini) trabalham autonomamente neste repo,
cada um em sessões isoladas sem memória compartilhada entre si. Isso já causou o mesmo
bug de integração (ex: enum `situacao` do Tiny invertido) ser "descoberto" e corrigido
mais de uma vez, em sessões diferentes, sem que a segunda soubesse que a primeira já
tinha mapeado o problema.

**Antes de integrar com um sistema externo (Tiny/Olist, Mercado Pago, Melhor Envio,
Mercado Livre) ou investigar um bug que parece familiar, procure em `docs/*.md` por um
arquivo já existente sobre aquele sistema.** Se você descobrir algo não-óbvio sobre uma
API externa (um campo com nome diferente do esperado, um enum com significado
contra-intuitivo, um limite de taxa, um comportamento assíncrono/eventual-consistency),
**registre em `docs/<SISTEMA>.md`** (crie se não existir, seguindo o formato de
`docs/TINY-ERP-API-V3.md`) em vez de deixar esse conhecimento morrer com a sessão atual.
O objetivo é que cada agente que passar por aqui saia mais capaz que o anterior — não
que cada um recomece do zero.

---

## 📝 Checklist de Manutenção Semanal

- [ ] Verificar logs em `/logs/` por errors
- [ ] Testar fluxo: commit → push → deploy (5 min)
- [ ] Validar endpoints críticos em produção
- [ ] Revisar `tasks-queue.json` (concluídas vs pendentes)
- [ ] Atualizar secrets se algum API expirou
- [ ] Fazer backup de dados críticos

---

## 🎯 Próximos Passos

1. **Você (C:\Users\FRED):**
   - Resolver merge conflict conforme instruções acima
   - Fazer push para main
   - Notificar conclusão

2. **Sistema (Automático):**
   - QA Lint validará código
   - Deploy sincronizará com HostGator
   - Auto-validation rodará a cada 30 min

3. **Monitoramento:**
   - Abra: https://github.com/fredmourao-ai/site-shopvivaliz/actions
   - Monitore execução dos 4 workflows
   - Verificar health check pós-deploy

---

## 📞 Suporte

Se algo não funcionar:
1. Checar logs relevantes em `/logs/` ou GitHub Actions
2. Verificar erro específico conforme Troubleshooting acima
3. Se necessário, rodar: `git fetch && git pull origin main`

**Repositório:** https://github.com/Vivaliz-site/site-shopvivaliz  
**Live Site:** https://dev.shopvivaliz.com.br/  
**Admin Monitor:** https://dev.shopvivaliz.com.br/admin/monitor/  
**Histórico de bugs resolvidos:** `CHANGELOG.md` (raiz do repo) — consulte antes de investigar algo
que parece já ter sido corrigido.

---

**Sistema integrado e funcionando. Pronto para produção. 🚀**
