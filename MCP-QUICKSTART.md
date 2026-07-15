# 🌉 MCP (Model Context Protocol) - Quick Start

**Status:** ✅ Implementado e Pronto para Uso  
**Data:** 2026-07-13  
**Versão:** 1.0.0

---

## 📋 O Que É MCP?

MCP permite que **Claude acesse recursos e execute ferramentas em múltiplas estações** de forma real-time:

```
Claude em Estação A → MCP Client → MCP Server (Estação B, Ubuntu, etc)
                                        ↓
                               [Recursos disponíveis]
                               [Tools executáveis]
```

---

## 🚀 Início Rápido

### 1️⃣ Instalar Dependências

**Windows:**
```powershell
pip install aiohttp requests
```

**Ubuntu:**
```bash
pip3 install aiohttp requests
```

### 2️⃣ Iniciar MCP Server

**Em cada máquina que quer compartilhar:**

**Windows Local (porta 5555):**
```powershell
python scripts/mcp-server.py --port 5555 --env windows-local
```

**Ubuntu VM (porta 5556):**
```bash
cd /home/ubuntu/site-shopvivaliz
python3 scripts/mcp-server.py --port 5556 --env ubuntu-vm --host 0.0.0.0
```

**Fred-Win (porta 5557):**
```powershell
python scripts/mcp-server.py --port 5557 --env fred-win
```

### 3️⃣ Testar Conexão

```bash
# Health check
python scripts/mcp-client.py --server localhost:5555 --health

# Listar recursos
python scripts/mcp-client.py --server localhost:5555 --list-resources

# Listar tools
python scripts/mcp-client.py --server localhost:5555 --list-tools
```

---

## 📚 Recursos Disponíveis

Cada MCP Server oferece estes recursos (Claude pode ler/escrever):

| Recurso | Type | Descrição |
|---------|------|-----------|
| `status://system` | READ | Status do sistema/estação |
| `logs://sync` | READ | Logs de sincronização |
| `logs://agentes` | READ | Logs de execução de agentes |
| `config://env` | READ | Variáveis de ambiente |
| `files://tasks` | R/W | Fila de tarefas (tasks-queue.json) |
| `repo://git-status` | READ | Status do repositório git |
| `sync://stats` | READ | Estatísticas de sincronização |

### Exemplo: Ler Status do Sistema

```bash
python scripts/mcp-client.py \
  --server localhost:5555 \
  --read-resource status://system
```

**Resultado:**
```json
{
  "resource": "status://system",
  "content": {
    "environment": "windows-local",
    "timestamp": "2026-07-13T20:30:00Z",
    "git_status": "clean",
    "mcp_server": "online (port 5555)"
  }
}
```

---

## 🛠️ Tools Disponíveis

Claude pode executar estas tools:

| Tool | Descrição | Exemplo |
|------|-----------|---------|
| `execute_git_command` | Rodar git commands | `git pull origin main` |
| `read_file` | Ler arquivo | `tasks-queue.json` |
| `write_file` | Escrever arquivo | Atualizar tasks |
| `execute_command` | Rodar shell/PowerShell | Qualquer comando |
| `get_logs` | Obter logs | `log_type: "sync"` |

### Exemplo: Executar Git Pull

```bash
python scripts/mcp-client.py \
  --server localhost:5555 \
  --execute-tool execute_git_command \
  '{"command": "pull origin main"}'
```

### Exemplo: Ler Arquivo

```bash
python scripts/mcp-client.py \
  --server localhost:5555 \
  --execute-tool read_file \
  '{"path": "tasks-queue.json"}'
```

---

## 🔄 Fluxo Prático

### Cenário: Claude Sincronizando Dados Entre Estações

```
1. Claude em Windows recebe requisição via GitHub Issue
   → Lê tasks-queue.json via MCP (status://tasks)

2. Precisa sincronizar com Ubuntu VM
   → Abre conexão MCP com ubuntu-vm:5556
   → Executa `execute_git_command` → "pull origin main"

3. Ubuntu VM faz o pull
   → Retorna: "✅ Sync concluído"

4. Claude em Windows comenta na issue
   → "✅ Sincronização concluída em todos os servidores"
```

---

## 📡 Comunicação Multi-Servidor

### Configuração de Servidores

Editar `mcp-servers.json`:

```json
{
  "servers": {
    "windows-local": {
      "url": "http://localhost:5555",
      "enabled": true
    },
    "ubuntu-vm": {
      "url": "http://137.131.156.17:5556",
      "enabled": true
    },
    "fred-win": {
      "url": "http://192.168.1.100:5557",
      "enabled": true
    }
  }
}
```

### Descobrir Servidores Disponíveis

```bash
python scripts/mcp-client.py --list-servers
```

**Resultado:**
```json
{
  "windows-local": {
    "url": "http://localhost:5555",
    "status": "online",
    "health": {"status": "ok", "environment": "windows-local"}
  },
  "ubuntu-vm": {
    "url": "http://137.131.156.17:5556",
    "status": "offline",
    "health": {"error": "Connection refused"}
  }
}
```

---

## 🔐 Segurança

### Considerações Importantes

- ⚠️ MCP Servers rodam localmente (não expostos à internet)
- ✅ Implementar firewall se conectar de múltiplas redes
- ✅ Usar HTTPS em produção (adicionar SSL)
- ✅ Logs são auditados em cada estação

### Configuração de Firewall (Exemplo Ubuntu)

```bash
# Permitir apenas IP local
sudo ufw allow from 192.168.1.0/24 to any port 5556
```

---

## 📊 Monitoramento

### Ver Logs do MCP Server

```bash
# Windows
Get-Content logs/mcp-server-windows-local.log -Tail 50

# Ubuntu
tail -f logs/mcp-server-ubuntu-vm.log
```

### Health Check Automático

GitHub Actions roda a cada 15 minutos:

```bash
# Verificar status
gh run list --workflow mcp-servers.yml
```

---

## 🎯 Casos de Uso

### 1. Claude em Windows Executar Script em Ubuntu

```python
# Claude chama:
mcp.execute_tool("execute_command", {
    "command": "python3 /home/ubuntu/site-shopvivaliz/scripts/shopee-sync.py"
})
# Ubuntu executa e retorna resultado
```

### 2. Claude Compartilhar Dados Entre Estações

```python
# Windows lê tasks-queue.json
windows_tasks = mcp_windows.read_resource("files://tasks")

# Ubuntu escreve resultado
mcp_ubuntu.write_resource("files://tasks", updated_tasks)
```

### 3. Claude Monitorar Saúde de Todos os Servidores

```python
for server_name, mcp_client in mcp_servers.items():
    health = mcp_client.health_check()
    print(f"{server_name}: {health['status']}")
```

---

## 📋 Setup Completo (Passo a Passo)

### Estação 1: Windows Local

```powershell
# 1. Instalar
pip install aiohttp requests

# 2. Iniciar servidor (mantenha rodando)
python scripts/mcp-server.py --port 5555 --env windows-local

# 3. Em outro PowerShell, testar
python scripts/mcp-client.py --server localhost:5555 --health
```

### Estação 2: Ubuntu VM

```bash
# SSH para Ubuntu
ssh ubuntu@137.131.156.17

# 1. Instalar
pip3 install aiohttp requests

# 2. Iniciar servidor
cd /home/ubuntu/site-shopvivaliz
python3 scripts/mcp-server.py --port 5556 --env ubuntu-vm --host 0.0.0.0

# 3. Testar de Windows
python scripts/mcp-client.py --server 137.131.156.17:5556 --health
```

### Estação 3: Fred-Win

```powershell
# Mesmo processo que Windows Local
# Apenas mude port=5557 e env=fred-win
python scripts/mcp-server.py --port 5557 --env fred-win
```

### Configurar `.env.agentes.local` em cada máquina

```env
AGENT_ENVIRONMENT=windows-local
MCP_PORT=5555
MCP_ENABLED=true
```

---

## 🚨 Troubleshooting

### Erro: Connection refused (porta não responde)

```bash
# Verificar se servidor está rodando
netstat -ano | findstr :5555  # Windows
ss -tlnp | grep 5555          # Ubuntu

# Tentar reiniciar
python scripts/mcp-server.py --port 5555 --env windows-local
```

### Erro: Timeout ao conectar

```bash
# Verificar conectividade entre máquinas
ping 137.131.156.17
curl http://137.131.156.17:5556/health
```

### Tool execution falhou

```bash
# Ver logs detalhados
tail -100 logs/mcp-server-*.log

# Rerun com debug
python scripts/mcp-server.py --port 5555 --env windows-local --debug
```

---

## 📞 Próximos Passos

1. **Instalar MCP Servers** em todas as estações
2. **Testar Conexões** com mcp-client.py
3. **Integrar com Agentes** - adicionar MCP calls em agentes-leitor.py
4. **Monitorar** via GitHub Actions workflow
5. **Documentar** IPs e portas em CLAUDE.md

---

**Documentação Completa:** [MCP-SERVERS.md](./MCP-SERVERS.md)  
**Scripts:** `scripts/mcp-server.py`, `scripts/mcp-client.py`  
**Configuração:** `mcp-servers.json`

🎯 **MCP agora ativa a comunicação real-time entre Claude em todas as estações!**
