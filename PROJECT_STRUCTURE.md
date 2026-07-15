# 📁 Estrutura do Projeto ShopVivaliz

## Organização Completa

```
site-shopvivaliz/
│
├── 📄 README.md                          ← Documentação principal
├── 📄 CLAUDE.md                          ← Instruções para Claude
├── 📄 QUICK_START.md                     ← Início rápido
├── 📄 PROJECT_STRUCTURE.md               ← Este arquivo
├── 🔧 Makefile                           ← Comandos facilitadores
├── 📦 requirements.txt                   ← Dependências Python
│
├── 🚀 SISTEMA DE COMUNICAÇÃO
│   ├── 📄 PONTE-AGENTES-README.md        ← Ponte GitHub Issues
│   ├── 📄 AGENTES-REQUISICAO-AUTO-SYNC.md
│   ├── 📄 MCP-QUICKSTART.md              ← MCP Protocol
│   └── 📄 MCP-SERVERS.md
│
├── 🛠️ SCRIPTS (Automação)
│   ├── shopvivaliz-cli.py               ← CLI principal
│   ├── shopvivaliz_dashboard.py         ← Dashboard web
│   ├── shopvivaliz_db.py                ← Database (SQLite)
│   ├── shopvivaliz_notify.py            ← Notificações
│   ├── mcp-server.py                    ← MCP Server
│   ├── mcp-client.py                    ← MCP Client
│   ├── agentes-leitor.py                ← Issue Listener
│   ├── local-auto-sync.ps1              ← Sync automático
│   ├── git-auto-sync.py                 ← Sync Linux/Ubuntu
│   └── automation/
│       ├── eight_hour_status_email.py
│       ├── hourly_status_email.py
│       └── ... (mais scripts de automação)
│
├── 🔄 WORKFLOWS (GitHub Actions)
│   ├── .github/workflows/
│   │   ├── shopvivaliz-qa.yml
│   │   ├── eight-hour-status-email.yml
│   │   ├── agentes-listener.yml         ← Listener de Issues
│   │   ├── mcp-servers.yml              ← Health check MCP
│   │   ├── auto-validation-and-fix.yml
│   │   ├── deploy.yml
│   │   └── ... (mais workflows)
│   │
│   └── ISSUE_TEMPLATE/
│       └── agentes-requisicao.md        ← Template de Issue
│
├── 📋 CONFIGURAÇÃO
│   ├── .env.agentes                     ← Configuração de agentes
│   ├── .env.agentes.local               ← Overrides locais
│   ├── .env.local                       ← Secrets locais
│   ├── mcp-servers.json                 ← Config MCP Servers
│   ├── tasks-queue.json                 ← Fila de tarefas
│   └── .gitignore
│
├── 📊 DADOS & LOGS
│   ├── logs/
│   │   ├── local-sync-*.log             ← Auto-sync logs
│   │   ├── agentes-leitor-*.log         ← Issue listener logs
│   │   ├── mcp-server-*.log             ← MCP Server logs
│   │   └── ...
│   │
│   ├── shopvivaliz.db                   ← Database SQLite
│   └── reports/
│       └── ... (relatórios automáticos)
│
├── 🌐 SITE (Código da aplicação)
│   ├── index.php
│   ├── css/
│   ├── js/
│   ├── admin/
│   └── ... (estrutura do e-commerce)
│
└── 📚 DOCUMENTAÇÃO ADICIONAL
    ├── CHANGELOG.md
    ├── CLAUDE-AUTONOMO.md
    ├── AUTOMATION-IA-DOCUMENTATION.md
    └── ... (mais docs)
```

---

## 🎯 Principais Componentes

### 1. **CLI Tool** (`shopvivaliz-cli.py`)
Interface centralizada para tudo:
```bash
shopvivaliz status          # Ver status
shopvivaliz logs            # Ver logs
shopvivaliz sync            # Forçar sync
shopvivaliz task --list     # Listar tarefas
shopvivaliz dashboard       # Abrir web UI
shopvivaliz mcp             # Chamar MCP
```

### 2. **Dashboard Web** (`shopvivaliz_dashboard.py`)
- Status de todas as estações em tempo real
- Tarefas pendentes
- Logs recentes
- http://localhost:8888

### 3. **Database** (`shopvivaliz_db.py`)
SQLite com tabelas:
- `syncs` - Histórico de sincronizações
- `tasks` - Rastreamento de tarefas
- `events` - Timeline de eventos
- `metrics` - Performance data

### 4. **MCP Servers** (`mcp-server.py` + `mcp-client.py`)
Comunicação entre estações:
- Acesso a recursos remotos (logs, status, arquivos)
- Execução de ferramentas (git, shell commands)
- Real-time communication

### 5. **Ponte de Agentes** (`agentes-leitor.py`)
Monitorar GitHub Issues:
- Ler requisições com label "agentes"
- Executar em múltiplas estações
- Comentar resultado

### 6. **Auto-Sync** (`local-auto-sync.ps1`)
Sincronização automática a cada 30 minutos:
- Pull + Rebase
- Push de mudanças
- Logging de todas operações

### 7. **Notificações** (`shopvivaliz_notify.py`)
Alertas em caso de erro:
- Email (SMTP)
- GitHub Issues/Comments
- Integração com agentes

---

## 🔄 Fluxo de Funcionamento

```
Estação A (Windows)
  ↓
  shopvivaliz-cli.py (interface)
  ↓
Camada de Automação
  ├─ auto-sync.ps1 (30 min)
  ├─ agentes-leitor.py (monitora GitHub)
  └─ mcp-server.py (porta 5555)
  ↓
Comunicação
  ├─ GitHub Issues (requisições)
  ├─ MCP Protocol (resources + tools)
  └─ Database (histórico)
  ↓
Estação B (Ubuntu VM) + Estação C (Fred-Win)
  ├─ Recebem requisições
  ├─ Executam tarefas via MCP
  └─ Reportam status
  ↓
Dashboard & Monificações
  ├─ Web UI (status real-time)
  ├─ Alerts (email/GitHub)
  └─ Logs (auditoria completa)
```

---

## 📊 Dados Armazenados

### Database (SQLite)
- **Syncs**: 50+ por dia
- **Tasks**: Rastreamento completo
- **Events**: Timeline de tudo
- **Metrics**: Performance tracking

### Logs (Arquivos)
- **local-sync-YYYY-MM-DD.log** (~50KB/dia)
- **agentes-leitor-YYYY-MM-DD.log** (~30KB/dia)
- **mcp-server-YYYY-MM-DD.log** (~20KB/dia)

Limpeza automática: 30+ dias

---

## 🚀 Começar

```bash
# 1. Instalar
make install

# 2. Setup
make setup

# 3. CLI
python scripts/shopvivaliz-cli.py status

# 4. Dashboard
make dashboard
```

---

## 📖 Referência Rápida

| Necessidade | Comando | Arquivo |
|-----------|---------|---------|
| Ver status | `make status` | shopvivaliz-cli.py |
| Dashboard | `make dashboard` | shopvivaliz_dashboard.py |
| Logs | `make logs` | shopvivaliz-cli.py |
| Sincronizar | `make sync` | local-auto-sync.ps1 |
| MCP Health | `make mcp-health` | mcp-client.py |
| Tarefas | `make task` | shopvivaliz-cli.py |
| Testes | `make test` | pytest |

---

**Última atualização:** 2026-07-13  
**Versão:** 1.0.0
