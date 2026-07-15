# 🤖 Sistema Autônomo Completo 24/7 - ShopVivaliz

## Arquitetura de Três Camadas

```
┌─────────────────────────────────────────────────────────────────────┐
│ CAMADA 1: ORQUESTRADOR CENTRAL (GitHub Actions)                     │
│ - Dispara workflows a cada 5/10/30 minutos                          │
│ - Sincroniza repositório remoto com local                           │
│ - Coordena execução de agentes IA                                   │
│ - Monitora saúde do sistema 24/7                                    │
└─────────────────────────────────────────────────────────────────────┘
                            ↑
        ┌───────────────────┼───────────────────┐
        │                   │                   │
┌───────▼────────┐ ┌────────▼─────────┐ ┌──────▼────────────┐
│  Auto-Pull     │ │  Auto-Push       │ │ Auto-Deploy       │
│ (5 min)        │ │  (10 min)        │ │ FTP (on-change)   │
│                │ │  (detect changes)│ │                   │
└────────────────┘ └──────────────────┘ └───────────────────┘
                            ↑
┌─────────────────────────────────────────────────────────────────────┐
│ CAMADA 2: AUTOMAÇÃO LOCAL (Windows + Python)                        │
│ - Task Scheduler: Auto-sync local (C:\FRED ↔ c:/user) a cada 2 min  │
│ - Auto-resolve merge conflicts                                      │
│ - Auto-git-pull antes de sync                                       │
│ - Auto-git-push após mudanças detectadas                            │
│ - Monitoramento de saúde local (validações)                         │
└─────────────────────────────────────────────────────────────────────┘
                            ↑
┌─────────────────────────────────────────────────────────────────────┐
│ CAMADA 3: EXECUTORES DE TAREFAS (Gemini + Claude + GPT)             │
│ - Task executor a cada 30 minutos                                   │
│ - Análise autônoma de código/estrutura                              │
│ - Auto-geração de correções/melhorias                               │
│ - Auto-validação de saída                                           │
│ - Auto-commit e push                                                │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Fluxo de Operações 24/7

### MINUTO 0: Auto-Pull (GitHub → Local)
```
├─ Git pull origin main
├─ Resolver conflitos automaticamente
├─ Detectar mudanças
└─ Notificar Local Sync
```

### MINUTO 2: Auto-Sync Local (C:\FRED ↔ c:/user)
```
├─ Rsync bidirecional
├─ Ignorar arquivos de sistema
├─ Detectar mudanças em ambos os lados
└─ Commit automático se houver mudanças
```

### MINUTO 5: Auto-Pull (repetir)
```
├─ Git pull origin main
├─ Mesclar com mudanças locais
└─ Atualizar ambos os ambientes
```

### MINUTO 10: Auto-Push (Detectar mudanças)
```
├─ Git status --porcelain
├─ Se houver mudanças:
│  ├─ Git add .
│  ├─ Git commit com mensagem descritiva
│  └─ Git push origin main
└─ Ativar auto-deploy
```

### MINUTO 15: Auto-Deploy FTP (se houver push)
```
├─ Se último push < 15 min:
│  ├─ Conectar FTP
│  ├─ Upload arquivos modificados
│  ├─ Validar upload
│  └─ Log de deploy
└─ Senão: Aguardar próxima mudança
```

### MINUTO 30: Auto-Executor de Tarefas IA
```
├─ Gemini: Análise de estrutura (5 min)
│  ├─ Detecta problemas
│  └─ Gera relatório
├─ Claude: Executa correções (10 min)
│  ├─ Cria código
│  ├─ Commit local
│  └─ Push automático
└─ GPT: Validação final (5 min)
   ├─ Verifica qualidade
   └─ Aprova ou rejeita
```

### MINUTO 60: Validação de Saúde (Health Check)
```
├─ Testa 200+ arquivos PHP
├─ Valida 22 workflows YAML
├─ Verifica 17 endpoints API
├─ Testa conectividade FTP
└─ Gera relatório (logs/health-check.json)
```

---

## Arquivos a Criar/Atualizar

### 1. Scripts Python (automação local)
- `scripts/autonomous-sync.py` - Sincroniza C:\FRED ↔ c:/user
- `scripts/autonomous-git-pull.py` - Auto-pull + merge de conflitos
- `scripts/autonomous-git-push.py` - Auto-detect mudanças + push
- `scripts/autonomous-ftp-deploy.py` - FTP deployment
- `scripts/task-executor.py` - Executa tarefas com IA (Gemini/Claude/GPT)
- `scripts/health-check.py` - Validação de saúde contínua

### 2. Scripts PowerShell (Windows Task Scheduler)
- `scripts/schedule-auto-sync.ps1` - Cria tarefa "Auto-Sync" a cada 2 min
- `scripts/schedule-git-operations.ps1` - Cria tarefas Git (pull/push)
- `scripts/schedule-health-check.ps1` - Cria tarefa de health check

### 3. GitHub Actions Workflows
- `.github/workflows/sync-orchestrator.yml` - Orquestrador central (cada 5 min)
- `.github/workflows/auto-git-pull.yml` - Pull automático (cada 5 min)
- `.github/workflows/auto-git-push.yml` - Push automático (cada 10 min)
- `.github/workflows/auto-deploy-ftp.yml` - Deploy FTP (on-change)
- `.github/workflows/auto-task-executor.yml` - Task executor (cada 30 min)
- `.github/workflows/health-check.yml` - Health check (cada 60 min)

### 4. Configuração de Ambiente
- `config/autonomous-settings.json` - Configuração central
- `config/git-config.json` - Configuração Git
- `config/ftp-config.json` - Configuração FTP (secrets)
- `config/ai-config.json` - Configuração IA (Gemini/Claude/GPT)

---

## Como Habilitar o Sistema

### Passo 1: Configurar Windows Task Scheduler
```powershell
# Executar como Administrator
.\scripts\schedule-auto-sync.ps1
.\scripts\schedule-git-operations.ps1
.\scripts\schedule-health-check.ps1
```

### Passo 2: Ativar GitHub Actions Workflows
```bash
git push origin main
# Workflows acionarão automaticamente
```

### Passo 3: Configurar Secrets no GitHub
```
PERSONAL_ACCESS_TOKEN
FTP_HOST
FTP_USER
FTP_PASS
GEMINI_API_KEY
CLAUDE_API_KEY (via MCP)
OPENAI_API_KEY
```

### Passo 4: Validar Sistema
```bash
python3 scripts/health-check.py
```

---

## Garantias do Sistema

1. ✅ **Zero downtime** - Operações não bloqueiam o site
2. ✅ **Auto-recovery** - Detecta e corrige erros automaticamente
3. ✅ **Merge conflict resolution** - Resolve conflitos sem intervenção
4. ✅ **Audit trail** - Todo commit tem histórico completo
5. ✅ **Rollback automático** - Se deploy falhar, reverte última versão
6. ✅ **Notificações** - Slack/Email se erro crítico ocorrer
7. ✅ **Sync contínua** - Ambientes locais sempre sincronizados
8. ✅ **Health monitoring** - Valida saúde a cada hora

---

## Monitoramento

Ver status em:
```
logs/
├─ health-check.json       (saúde do sistema)
├─ sync-report.json        (sincronização)
├─ git-operations.json     (pull/push)
├─ ftp-deploy.json         (deployment)
├─ task-executor.json      (execução de tarefas)
└─ validation-report.json  (validações)
```

Acessar dashboard ao vivo:
```
https://dev.shopvivaliz.com.br/admin/monitor/
```

---

## Troubleshooting

Se sistema parar:
1. Verificar `logs/health-check.json` para diagnóstico
2. Executar `python3 scripts/health-check.py --verbose`
3. Verificar GitHub Actions → Logs para erros de workflow
4. Verificar Windows Event Viewer para tarefas agendadas
5. Executar `git status` e `git log` para sincronização

Se conflitos de merge:
1. Sistema tenta resolver automaticamente
2. Se falhar: ver `logs/merge-conflicts.json`
3. Manual: `git merge --abort` + `git pull --rebase`

Se FTP não conectar:
1. Verificar `config/ftp-config.json`
2. Testar conexão: `python3 scripts/test-ftp.py`
3. Verificar firewall/VPN
4. Se certificado SSL: `openssl s_client -connect host:port`

---

## Status Atual

Sistema em construção. Arquivos serão criados nos passos seguintes.
Todos os scripts herdam a robustez do projeto existente.
