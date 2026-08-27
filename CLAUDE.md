# ShopVivaliz - Sistema Integrado de Automação

> Última atualização: 2026-07-26 (consolidação: 99→10 workflows, 31→2 scripts, agentes unificados)  
> Responsável: fredmourao-ai + Claude Code Autonomous  
> Status: ✅ Produção — deploy real via VM Oracle (não FTP)  
> Repositório real: **https://github.com/Vivaliz-site/site-shopvivaliz**

> 🔴 **LEIA ANTES DE COMEÇAR:**
> - **`docs/AGENTS.md`** ← ⭐ NOVO: Memória centralizada de todos os agentes + regras obrigatórias
> - **`KNOWN_ISSUES.md`**: Problemas recorrentes e em investigação
> - **`CHANGELOG.md`**: Histórico de bugs corrigidos e mudanças
>
> `docs/AGENTS.md` é o **único lugar centralizado** onde erros não-óbvios são registrados. **Leia antes de começar. Adicione uma entrada ao terminar se aprendeu algo.**

---

## 📊 Visão Geral do Sistema

ShopVivaliz é um **e-commerce de alto rendimento** com automação de:
- **Deploy:** produção real roda na VM Oracle `shopvivaliz-micro-2` (IP `136.248.69.116`) —
  ver `### 🔴 VM1 vs VM2 — não confundir (corrigido 2026-08-26)` logo abaixo antes de mexer em
  qualquer coisa em qualquer uma das VMs, principalmente `.env`. Não é FTP/HostGator.
- **Validação:** QA lint dispara em push/PR (`shopvivaliz-qa.yml`)
- **Execução de Tarefas:** Fila autônoma (`tasks-queue.json`), múltiplos workflows agendados
- **Agentes IA:** múltiplos agentes autônomos (Claude Code, e outros) commitam direto no repo —
  ver nota de risco abaixo

### 🔴 VM1 vs VM2 — não confundir (corrigido 2026-08-26)

**Confusão real que já aconteceu nesta sessão e pode se repetir:** este arquivo dizia até
2026-08-26 que `shopvivaliz-ai` (`137.131.156.17`, a VM original) era produção e que
`shopvivaliz-micro-2` (`136.248.69.116`) era só um destino temporário planejado. **Isso estava
errado/desatualizado.** Fred confirmou e foi verificado ao vivo (Apache vhost, logs de acesso
reais via Cloudflare, `Content-Security-Policy` enforced batendo com o código, worker de fila
de pagamentos ativo) que a realidade atual é:

| VM | IP | Papel real | Como confirmar |
|---|---|---|---|
| **VM1** `shopvivaliz-ai` | `137.131.156.17` | **DEV** — usado só para envio de e-mail (mei-mg-email) e testes. `ServerName dev.shopvivaliz.com.br` no Apache. **NÃO é produção**, apesar do nome "original". | `apache2ctl -S` mostra `dev.shopvivaliz.com.br`; `shopvivaliz_access.log` não tem tráfego real do Cloudflare |
| **VM2** `shopvivaliz-micro-2` | `136.248.69.116` | **PRODUÇÃO REAL** — é quem serve `shopvivaliz.com.br` de verdade. | `shopvivaliz_access.log` mostra hits reais de IPs Cloudflare (`104.22.x`, `172.68-71.x`) pedindo `/produto/...`, `/api/ml/webhook`; `CSP` enforced ao vivo bate com `.htaccess`; deploy atual (`readlink current`) tem os 10 commits das Rodadas 1-10 + fix da fila de pagamentos na ancestralidade (confirmado via `git merge-base --is-ancestor`) |
| `shopvivaliz-free-a1` | *(pendente)* | Destino final planejado quando a Oracle liberar capacidade Ampere A1.Flex (2 OCPU/12GB) — ver seção de retry abaixo. Quando for provisionada, o corte deve ser feito **a partir da VM2** (produção real), não da VM1. | Bloqueada por "Out of host capacity" da Oracle |

**Antes de assumir que uma correção "está em produção" só por ter validado na VM1, confirme
sempre em qual VM o teste foi feito.** Isso já causou 10 rodadas de correções + o fix da fila
de pagamentos serem aplicados via `main` (o que é automático pro deploy de qualquer VM que
rode o cron), mas a *validação ao vivo* de várias dessas rodadas foi feita testando a VM1,
achando (erroneamente) que ela era produção. A boa notícia: como o deploy de ambas as VMs
puxa do mesmo `main` via cron, os fixes de código chegaram na VM2 (produção real) de qualquer
forma — confirmado retroativamente em 2026-08-26 (ver `docs/AGENTS.md`, entrada do mesmo dia).
Mas a *validação ao vivo* (curl, headers, systemd status) precisa ser refeita contra a VM2
sempre que houver dúvida.

Chave SSH (mesma para as 3 VMs): `ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key"
ubuntu@<IP>`. Também sincronizada nos secrets do GitHub `ORACLE_VM_SSH_KEY` +
`ORACLE_VM_KNOWN_HOSTS` (inclui os host keys das 3 VMs). Credenciais de API OCI (para
provisionar/gerenciar recursos via `oci` CLI) estão em `~/.oci/config` local e nos secrets
`OCI_CLI_USER`, `OCI_CLI_TENANCY`, `OCI_CLI_FINGERPRINT`, `OCI_CLI_REGION`,
`OCI_CLI_KEY_CONTENT`.

**A VM2 (`shopvivaliz-micro-2`) já é a produção real desde antes de 2026-08-26** — não há mais
"corte de DNS pendente" pra ela (o tráfego real já está lá, confirmado por log de acesso). O
que resta pendente é só a migração futura pra `shopvivaliz-free-a1` quando ela for provisionada
(ver seção de retry abaixo) — esse corte, quando acontecer, deve partir da VM2 como origem.

---

### 🏗️ Arquitetura Real de Deploy

**Atualizado em 2026-07-30 após auditoria — a versão anterior deste documento (cron
`git-auto-sync.py` a cada 30min servindo o site direto) estava desatualizada. A VM tem
DOIS diretórios ativos com papéis distintos:**

```
┌─────────────────────────────────────────────────────────────────┐
│                  GitHub (Fonte de Verdade) — branch main         │
└────────────────────┬───────────────────────┬────────────────────┘
                     │                       │
   fetch a cada 2min │                       │ cron do daemon (30min/olist/etc)
                     ▼                       ▼
   /home/ubuntu/shopvivaliz-deploy/    /home/ubuntu/site-shopvivaliz/
   ├─ repo/          (clone git)       (checkout git separado)
   ├─ releases/<ts>-<sha>/  (imutável)  usado por:
   ├─ current -> releases/.../         - systemd: shopvivaliz-24x7,
   ├─ shared/.env  (ENV REAL)            agent-bridge, auto-sync,
   │                                     catalog-audit, mcp, orchestrator,
   │  Apache DocumentRoot aponta         shopee-token-renewer, sync
   │  para "current" -> SERVE O SITE   - crontab: olist refresh-token (3h),
   │                                     google-token-renewer (1h),
   │                                     indexnow-submit (diário),
   │                                     daemon-sync-products (6h)
   └─ deploy-production.sh roda a
      cada 2min via cron, auto-
      atualiza a si mesmo a partir
      do FETCH_HEAD antes de aplicar
```

**Regra prática:** o site público (`shopvivaliz.com.br`) e qualquer `.env` que afete requisições
HTTP (tokens de webhook, SMTP, etc.) vivem em `shopvivaliz-deploy/shared/.env`. Editar
`site-shopvivaliz/.env` não afeta o site — só afeta os daemons/serviços de fundo que rodam
daquele checkout. Confirme sempre com `grep DocumentRoot /etc/apache2/sites-enabled/*` antes de
assumir qual diretório está em jogo.

**FTP/HostGator (`deploy.yml`, `auto-ftp-deploy.yml`) está desativado** — só roda via
`workflow_dispatch` manual, não em push. O comentário no próprio `deploy.yml` confirma: "a producao
real e a VM Oracle... nao o HostGator".

⚠️ **Consolidação de 2026-07-26 revertida na prática (achado da Rodada 1 de melhoria contínua,
2026-08-18):** este documento afirmava "99 → 10 workflows" desde 07-26, mas `docs/MEMORIA-AGENTES.md`
já registrava "99 workflows ativos" numa entrada de 08-06 — ou seja, a contagem real já divergia do
que este arquivo dizia há pelo menos 12 dias, sem ninguém corrigir o texto. Contagem real em
2026-08-18: **249+ arquivos `.yml` ativos** em `.github/workflows/` (a contagem sobe continuamente —
ver nota abaixo), incluindo dezenas agendados (`schedule:`), alguns a cada 5–15 minutos. Múltiplos
agentes autônomos (Claude, GPT, Gemini — ver `docs/MEMORIA-AGENTES.md`) criam workflows novos
continuamente, o que é intencional (confirmado pelo Fred), mas significa que **este número muda com
frequência e não deve ser tratado como fixo**. Antes de assumir "só existem N workflows", rode
`ls .github/workflows/*.yml | wc -l` para conferir a contagem atual. A decisão de arquivar famílias
específicas de workflows (`tmp-*`, `fredwin-*`, `mei-email-*`, `audit-*` etc.) é estrutural e aguarda
aprovação explícita do Fred — não é algo pra um agente decidir sozinho.

Scripts também consolidados: 31 → 2 mestres (`olist-sync-master.py`, `git-auto-sync-master.py`)

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

3. Pull Request (PR) e Merge (OBRIGATÓRIO)
   ├─ Criar ou atualizar PR
   └─ Efetuar Merge para branch alvo ao finalizar as alterações locais (Toda alteração deve ser validada de forma visual e funcional pelo navegador, sem scripts, e seguir este fluxo)

4. GitHub Dispara Pipeline Automática
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
- O agente valida os checks e pode aprovar e fazer merge sem aguardar nova aprovação explícita, conforme `REGRAS-AGENTES-CENTRALIZADAS.md`
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

## ⚙️ Workflows Principais (amostra — a contagem real muda continuamente, rode `ls .github/workflows/*.yml | wc -l` antes de assumir qualquer número; ver Rodada 10, R10-6)

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
  via `deploy-production.sh`, não FTP. Mantido no repo só para caso o HostGator volte a ser usado.

### 4️⃣ `ai-autonomous-executor.yml` - Executor de Tarefas
- **Triggers:** Schedule a cada 30 min
- **Ação:** Lê `tasks-queue.json`, chama APIs de IA, implementa mudanças, auto-commit
- **Status:** ✅ **ATIVO**

### Deploy real (fora do GitHub Actions)
- Cron na VM Oracle (`/usr/local/lib/shopvivaliz/deploy-production.sh`, `*/2 * * * *`): faz fetch
  de `origin/main`, monta um release imutável em `shopvivaliz-deploy/releases/<timestamp>-<sha>/`
  e troca o symlink `current` — é isso que efetivamente coloca código em produção (ver diagrama em
  `### 🏗️ Arquitetura Real de Deploy` no topo deste arquivo).
- Para forçar deploy imediato sem esperar o cron:
  `ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@137.131.156.17
  "sudo /usr/local/lib/shopvivaliz/deploy-production.sh"`
- Chave SSH funcional confirmada em 2026-07-30: `C:\Users\FRED\Downloads\ssh-key-2026-07-04.key`
  (ver `docs/AGENTS.md` entrada 2026-07-29 "Toolkit local e alvo persistente de deploy").

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
**Live Site:** https://shopvivaliz.com.br/  
**Admin Monitor:** https://shopvivaliz.com.br/admin/monitor/  
**Histórico de bugs resolvidos:** `CHANGELOG.md` (raiz do repo) — consulte antes de investigar algo
que parece já ter sido corrigido.

---

**Sistema integrado e funcionando. Pronto para produção. 🚀**
