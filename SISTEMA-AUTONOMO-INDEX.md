# 📑 Índice - Sistema Autônomo 24/7 ShopVivaliz

## 🚀 Comece Aqui

1. **[GUIA-RAPIDO-ATIVACAO.md](GUIA-RAPIDO-ATIVACAO.md)** ← **LEIA PRIMEIRO**
   - Ativação em 15 minutos
   - 4 passos simples
   - Verificação rápida

## 📚 Documentação Principal

### Para Entender o Sistema
- [SISTEMA-AUTONOMO-COMPLETO.md](SISTEMA-AUTONOMO-COMPLETO.md)
  - Visão geral completa
  - Arquitetura de 3 camadas
  - Como funciona 24/7

- [ARQUITETURA-AUTONOMA-TECNICA.md](ARQUITETURA-AUTONOMA-TECNICA.md)
  - Detalhes técnicos profundos
  - Fluxos de sincronização
  - Tratamento de erros
  - Performance e escalabilidade

### Para Ativar
- [ATIVAR-SISTEMA-AUTONOMO.md](ATIVAR-SISTEMA-AUTONOMO.md)
  - Guia passo-a-passo detalhado
  - GitHub Secrets setup
  - Windows Task Scheduler
  - Validação
  - Troubleshooting

## 🔧 Configuração

- [config/autonomous-settings.json](config/autonomous-settings.json)
  - Configuração centralizada
  - Todos os parâmetros
  - Definições por componente

## 💻 Scripts Python

### Automação Local (Windows)
- [scripts/autonomous-sync.py](scripts/autonomous-sync.py)
  - Sincroniza C:\FRED ↔ c:/user
  - Executa a cada 2 minutos
  - Bidirecional

- [scripts/autonomous-git-push.py](scripts/autonomous-git-push.py)
  - Detecta mudanças locais
  - Auto-commit + auto-push
  - Executa a cada 10 minutos

- [scripts/autonomous-ftp-deploy.py](scripts/autonomous-ftp-deploy.py)
  - Deploy automático em FTP
  - Retry automático (3x)
  - Executa a cada 15 minutos

- [scripts/health-check.py](scripts/health-check.py)
  - Valida saúde do sistema
  - 7 componentes verificados
  - Gera relatório JSON
  - Executa a cada 60 minutos

### Windows Task Scheduler Setup
- [scripts/schedule-auto-sync.ps1](scripts/schedule-auto-sync.ps1)
  - Cria tarefa Auto-Sync
  - Intervalo: 2 minutos
  - Roda como Administrator

- [scripts/schedule-git-operations.ps1](scripts/schedule-git-operations.ps1)
  - Cria tarefas de Git
  - Auto-Pull (5 min)
  - Auto-Push (10 min)
  - Auto-FTP-Deploy (15 min)

## ⚙️ GitHub Actions Workflows

### Orquestrador Central
- [.github/workflows/autonomous-orchestrator.yml](.github/workflows/autonomous-orchestrator.yml)
  - Coordena todas as operações
  - Roda a cada 5 minutos
  - GitHub Actions (nuvem)

### Git Operations
- [.github/workflows/auto-git-pull.yml](.github/workflows/auto-git-pull.yml)
  - Fetch + merge automático
  - Estratégia: -X ours
  - A cada 5 minutos

- [.github/workflows/auto-git-push.yml](.github/workflows/auto-git-push.yml)
  - Detecta mudanças
  - Auto-commit + push
  - A cada 10 minutos

### Deployment
- [.github/workflows/auto-ftp-deploy.yml](.github/workflows/auto-ftp-deploy.yml)
  - Deploy em FTP
  - On-change trigger
  - A cada 15 minutos

### Monitoramento
- [.github/workflows/health-check.yml](.github/workflows/health-check.yml)
  - Validação de saúde
  - 7 componentes
  - A cada 60 minutos

## 📊 Logging e Monitoramento

### Logs Gerados Automaticamente

```
logs/
├─ autonomous-sync.log           (detalhes)
├─ autonomous-sync.json          (status estruturado)
├─ autonomous-git-push.log       (detalhes)
├─ autonomous-git-push.json      (status + commits)
├─ autonomous-ftp-deploy.log     (detalhes)
├─ autonomous-ftp-deploy.json    (status + arquivos)
├─ health-check.log              (detalhes)
├─ health-check.json             (status + componentes)
└─ orchestrator-report.json      (status orquestrador)
```

### Como Consultar
```bash
# Ver último sync
cat logs/autonomous-sync.json

# Ver último push
cat logs/autonomous-git-push.json

# Ver última saúde do sistema
cat logs/health-check.json
```

## 🎯 Timeline de Execução

```
MINUTO 0:  Orchestrator inicia (GitHub)
MINUTO 2:  Auto-Sync (Windows)
MINUTO 5:  Auto-Pull (GitHub + Windows)
MINUTO 10: Auto-Push (Windows)
MINUTO 15: Auto-FTP-Deploy (Windows)
MINUTO 30: Task Executor (futuro)
MINUTO 60: Health-Check (GitHub)
```

## 🔐 Segurança

### GitHub Secrets (Criptografadas)
- `FTP_HOST` - Host FTP
- `FTP_USER` - Usuário FTP
- `FTP_PASS` - Senha FTP

### Permissões
- Scripts Python: 755 (executable)
- Configs: 600 (read-only)
- Logs: 644 (readable)

## 📈 Status do Sistema

### Componentes Ativos

- ✅ **Orchestrator** - Coordena operações (5 min)
- ✅ **Auto-Sync** - Sincroniza ambientes (2 min)
- ✅ **Auto-Pull** - Fetch remoto (5 min)
- ✅ **Auto-Push** - Commit + push (10 min)
- ✅ **Auto-Deploy** - FTP upload (15 min)
- ✅ **Health-Check** - Validação (60 min)

### Garantias
- ✅ Zero downtime
- ✅ Auto-recovery em falhas
- ✅ Merge conflict resolution
- ✅ Audit trail completo
- ✅ Redundância (GitHub + Local)

## 🛠️ Troubleshooting

### Verificação Rápida
```bash
python scripts/health-check.py
```

### Se Task Scheduler não roda
```powershell
Get-ScheduledTask -TaskName "ShopVivaliz-*"
Enable-ScheduledTask -TaskName "ShopVivaliz-AutoSync"
Start-ScheduledTask -TaskName "ShopVivaliz-AutoSync"
```

### Se FTP não funciona
```bash
python scripts/autonomous-ftp-deploy.py --verbose
cat logs/autonomous-ftp-deploy.json
```

### Ver GitHub Actions
```
https://github.com/fredmourao-ai/site-shopvivaliz/actions
```

## 📝 Histórico de Mudanças

| Data | Versão | Mudança |
|------|--------|---------|
| 2026-07-03 | 1.0.0 | Versão inicial - Sistema completo |

## 🎓 Como Funciona

### Sincronização Bidirecional (2 min)
```
C:\FRED\site-shopvivaliz ↔ c:/user/site-shopvivaliz
     ↓
Git add/commit automático
```

### Auto-Pull (5 min)
```
GitHub (origin/main)
     ↓
Git fetch + merge -X ours
     ↓
Conflitos resolvidos automaticamente
```

### Auto-Push (10 min)
```
Detecta mudanças locais
     ↓
Git add + commit automático
     ↓
Git push origin main
```

### Auto-Deploy (15 min)
```
Detecta novo push
     ↓
Identifica arquivos mudados
     ↓
Upload para FTP
     ↓
Retry se falhar (3x)
```

## 🚀 Próximos Passos

### Ativar Agora
1. Seguir [GUIA-RAPIDO-ATIVACAO.md](GUIA-RAPIDO-ATIVACAO.md)
2. 15 minutos de setup
3. Sistema roda 24/7

### Evoluir Depois
- Integrar IA (Gemini/Claude/GPT)
- Adicionar notificações Slack/Email
- Dashboard em tempo real
- Analytics de performance
- Rollback automático

## 📞 Suporte

1. Verificar `logs/health-check.json`
2. Rodar `python scripts/health-check.py --verbose`
3. Ver GitHub Actions logs
4. Verificar Windows Event Viewer

---

## 📖 Ordem de Leitura Recomendada

**Para Usuários:**
1. GUIA-RAPIDO-ATIVACAO.md (15 min)
2. ATIVAR-SISTEMA-AUTONOMO.md (detalhes)

**Para Desenvolvedores:**
1. SISTEMA-AUTONOMO-COMPLETO.md (visão geral)
2. ARQUITETURA-AUTONOMA-TECNICA.md (deep dive)
3. Scripts Python (implementação)
4. Workflows GitHub Actions (orquestração)

**Para Administradores:**
1. SISTEMA-AUTONOMO-COMPLETO.md (overview)
2. config/autonomous-settings.json (configuração)
3. ATIVAR-SISTEMA-AUTONOMO.md (setup)
4. Monitoramento (logs)

---

**Sistema Pronto para Produção**
Versão: 1.0.0
Data: 2026-07-03
Status: ✅ ATIVO
