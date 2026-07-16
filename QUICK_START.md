# 🚀 Quick Start - ShopVivaliz Sistema Completo

**5 MINUTOS PARA COMEÇAR**

---

## ✅ Passo 1: Instalar (2 min)

```bash
cd c:\site-shopvivaliz
pip install -r requirements.txt
```

✅ Pronto! Todas dependências instaladas.

---

## ✅ Passo 2: Verificar Status (1 min)

```bash
python scripts/shopvivaliz-cli.py status
```

Verá:
- 🟢 Estações online
- ℹ️ Uptime de cada uma
- 📊 Syncs realizados

---

## ✅ Passo 3: Abrir Dashboard (1 min)

```bash
python scripts/shopvivaliz-cli.py dashboard
```

Abrir no navegador: **http://localhost:8888**

Vê em tempo real:
- Status de todas estações
- Tarefas pendentes
- Logs recentes

---

## ✅ Passo 4: Testar Sincronização (1 min)

```bash
python scripts/shopvivaliz-cli.py sync
```

Verá:
```
🔄 Sincronizando 1 estação(s)...

  windows-local: ✅ Sucesso

📊 Resumo:
  windows-local: ✅ Sucesso
```

---

## 🎯 Comandos Principais

### Status & Monitoramento
```bash
python scripts/shopvivaliz-cli.py status          # Ver todas estações
python scripts/shopvivaliz-cli.py logs all        # Todos logs
python scripts/shopvivaliz-cli.py logs ubuntu-vm  # Logs de uma estação
python scripts/shopvivaliz-cli.py health          # Health check completo
```

### Sincronização
```bash
python scripts/shopvivaliz-cli.py sync            # Sync sequencial
python scripts/shopvivaliz-cli.py sync --parallel # Sync paralelo
python scripts/shopvivaliz-cli.py sync --server ubuntu-vm  # Uma estação
```

### Tarefas
```bash
python scripts/shopvivaliz-cli.py task --list             # Listar pendentes
python scripts/shopvivaliz-cli.py task --list --status done  # Concluídas
python scripts/shopvivaliz-cli.py task --create           # Criar interativa
```

### Dashboard & MCP
```bash
python scripts/shopvivaliz-cli.py dashboard      # Web UI (8888)
python scripts/shopvivaliz-cli.py dashboard --port 9000  # Porta custom
python scripts/shopvivaliz-cli.py mcp             # Chamar MCP Server
```

---

## 🌉 Usar Ponte de Agentes

### 1. Criar Requisição no GitHub
```bash
# Manualmente: https://github.com/Vivaliz-site/site-shopvivaliz/issues/new
# Template: "🤖 Requisição Para Agentes Autônomos"

# Ou via CLI:
gh issue create \
  --title "Sincronizar dados Shopee" \
  --body "Descrição da tarefa" \
  --label agentes
```

### 2. Agentes Monitoram Automaticamente
```bash
# Roda a cada 30 min automaticamente via GitHub Actions
# Ou manual:
python scripts/agentes-leitor.py --watch
```

### 3. Resultado Aparece na Issue
```
🚀 [windows-local] Executando...
✅ [ubuntu-vm] Concluído
✅ [fred-win] Concluído
```

---

## 📊 MCP Servers (Comunicação Real-Time)

### Iniciar em Cada Máquina

**Windows Local:**
```powershell
python scripts/mcp-server.py --port 5555 --env windows-local
```

**Ubuntu VM:**
```bash
ssh ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
python3 scripts/mcp-server.py --port 5556 --env ubuntu-vm --host 0.0.0.0
```

**Fred-Win:**
```powershell
python scripts/mcp-server.py --port 5557 --env fred-win
```

### Testar Conexão
```bash
python scripts/mcp-client.py --server localhost:5555 --health
python scripts/mcp-client.py --server 137.131.156.17:5556 --health
python scripts/mcp-client.py --list-servers
```

---

## 🗂️ Fazer Usando Makefile (Mais Fácil!)

```bash
make help          # Ver todos comandos
make install       # Instalar deps
make setup         # Setup completo
make status        # Status de tudo
make dashboard     # Abrir web UI
make logs          # Ver logs
make sync          # Sincronizar
make mcp-health    # Health check MCP
make clean         # Limpar temporários
```

---

## 📚 Estrutura do Projeto

```
├── scripts/
│   ├── shopvivaliz-cli.py        ← CLI PRINCIPAL
│   ├── shopvivaliz_dashboard.py  ← DASHBOARD WEB
│   ├── shopvivaliz_db.py         ← DATABASE
│   ├── shopvivaliz_notify.py     ← NOTIFICAÇÕES
│   ├── mcp-server.py             ← MCP SERVER
│   ├── mcp-client.py             ← MCP CLIENT
│   ├── agentes-leitor.py         ← ISSUE LISTENER
│   └── local-auto-sync.ps1       ← AUTO-SYNC
│
├── .github/workflows/
│   ├── agentes-listener.yml      ← MONITORA ISSUES
│   ├── mcp-servers.yml           ← HEALTH CHECK
│   └── ...
│
├── mcp-servers.json              ← CONFIG MCP
├── tasks-queue.json              ← FILA TAREFAS
├── shopvivaliz.db                ← DATABASE SQLITE
│
├── QUICK_START.md                ← VOCÊ ESTÁ AQUI
├── PROJECT_STRUCTURE.md          ← FULL OVERVIEW
├── MCP-QUICKSTART.md             ← MCP GUIDE
├── PONTE-AGENTES-README.md       ← AGENTS BRIDGE
└── Makefile                       ← EASY COMMANDS
```

---

## ⚡ TL;DR (30 segundos)

```bash
# 1. Instalar
pip install -r requirements.txt

# 2. Ver status
python scripts/shopvivaliz-cli.py status

# 3. Abrir web UI
python scripts/shopvivaliz-cli.py dashboard

# 4. Pronto! 🎉
```

---

## 🆘 Troubleshooting

### Erro: "ModuleNotFoundError"
```bash
pip install -r requirements.txt
python -m pip install --upgrade pip
```

### Erro: "Connection refused"
```bash
# Verificar se MCP Server está rodando
python scripts/mcp-server.py --port 5555 --env windows-local
```

### Erro: "GitHub token not found"
```bash
# Configurar token
set GITHUB_TOKEN=seu_token_aqui
# ou no .env.agentes
```

---

## 📞 Próximos Passos

1. ✅ [x] Instalar e testar CLI
2. ✅ [x] Abrir Dashboard
3. [ ] Iniciar MCP Servers em todas estações
4. [ ] Criar primeira requisição de agente
5. [ ] Monitorar execução
6. [ ] Configurar notificações (email/GitHub)

---

**Dúvidas?** Leia:
- [PROJECT_STRUCTURE.md](./PROJECT_STRUCTURE.md) - Visão completa
- [MCP-QUICKSTART.md](./MCP-QUICKSTART.md) - MCP Protocol
- [PONTE-AGENTES-README.md](./PONTE-AGENTES-README.md) - Agentes

🚀 **Let's go!**
