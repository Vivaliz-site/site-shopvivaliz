# ⚡ Quick Start - AI Agents Remote Access

Guia rápido para agentes (GPT, Claude, Gemini) acessarem a máquina.

---

## 🚀 30 Segundos para Começar

### 1. Copie sua Chave API

```
GPT:     sk-openai-default-key
Claude:  sk-claude-default-key
Gemini:  sk-gemini-default-key
```

### 2. Use em Qualquer Request

```bash
curl -X GET http://137.131.156.17:5556/status \
  -H "X-API-Key: sk-claude-default-key"
```

### 3. Pronto! Agora você pode:

✅ Executar comandos
✅ Gerenciar git
✅ Ler/escrever arquivos
✅ Reiniciar serviços
✅ Monitorar sistema

---

## 🎯 Tarefas Comuns

### Task 1: Verificar Status

```bash
curl http://137.131.156.17:5556/status
```

**Resposta:**
```json
{
  "status": "online",
  "hostname": "shopvivaliz",
  "user": "shopvivaliz",
  "timestamp": "2026-07-16T18:30:45Z",
  "services": {
    "shopvivaliz-sync": "running",
    "shopvivaliz-mcp": "running",
    "ssh": "running"
  }
}
```

### Task 2: Executar Comando

```bash
curl -X POST http://137.131.156.17:5556/exec \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "cmd": "npm run build",
    "timeout": 60
  }'
```

### Task 3: Git Commit & Push

```bash
# 1. Check status
curl -X POST http://137.131.156.17:5556/git/status \
  -H "X-API-Key: sk-claude-default-key" \
  -d '{"path": "/home/shopvivaliz/site-shopvivaliz"}'

# 2. Commit
curl -X POST http://137.131.156.17:5556/git/commit \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "feat: update from AI",
    "path": "/home/shopvivaliz/site-shopvivaliz"
  }'

# 3. Push
curl -X POST http://137.131.156.17:5556/git/push \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "remote": "origin",
    "branch": "main",
    "path": "/home/shopvivaliz/site-shopvivaliz"
  }'
```

### Task 4: NPM Build & Deploy

```bash
# Install dependencies
curl -X POST http://137.131.156.17:5556/npm/install \
  -H "X-API-Key: sk-claude-default-key" \
  -d '{"path": "/home/shopvivaliz/site-shopvivaliz"}'

# Run build script
curl -X POST http://137.131.156.17:5556/npm/run \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "script": "build",
    "path": "/home/shopvivaliz/site-shopvivaliz"
  }'
```

### Task 5: Monitor Service

```bash
# Check status
curl -X POST http://137.131.156.17:5556/service/status \
  -H "X-API-Key: sk-claude-default-key" \
  -d '{"service": "shopvivaliz-sync"}'

# Get logs
curl -X POST http://137.131.156.17:5556/service/logs \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "service": "shopvivaliz-mcp",
    "lines": 20
  }'

# Restart service
curl -X POST http://137.131.156.17:5556/service/restart \
  -H "X-API-Key: sk-claude-default-key" \
  -d '{"service": "shopvivaliz-sync"}'
```

---

## 📚 All Endpoints

### GET Endpoints (No Auth)

```
GET /status          → System status
GET /health          → Health check
GET /logs?lines=20   → System logs
GET /tools           → Available tools
GET /providers       → Supported providers
```

### POST Endpoints (Requires Auth)

```
POST /exec                 → Execute command
POST /file/read            → Read file
POST /file/write           → Write file
POST /file/delete          → Delete file
POST /git/status           → Git status
POST /git/log              → Git log
POST /git/commit           → Git commit
POST /git/push             → Git push
POST /git/pull             → Git pull
POST /service/status       → Service status
POST /service/restart      → Restart service
POST /service/logs         → Service logs
POST /npm/install          → NPM install
POST /npm/run              → NPM run script
```

---

## 🔐 Authentication

All POST requests need header:
```
X-API-Key: sk-{provider}-default-key
```

Or use Authorization header:
```
Authorization: Bearer sk-{provider}-default-key
```

---

## ⚙️ Common Parameters

### exec
```json
{
  "cmd": "command to run",
  "timeout": 30
}
```

### file/read
```json
{
  "path": "/home/shopvivaliz/file.txt"
}
```

### file/write
```json
{
  "path": "/home/shopvivaliz/file.txt",
  "content": "file content here"
}
```

### git operations
```json
{
  "path": "/home/shopvivaliz/site-shopvivaliz",
  "message": "commit message",
  "remote": "origin",
  "branch": "main",
  "lines": 10
}
```

### npm operations
```json
{
  "path": "/home/shopvivaliz/site-shopvivaliz",
  "script": "build"
}
```

### service operations
```json
{
  "service": "shopvivaliz-sync",
  "lines": 20
}
```

---

## ⚠️ Rate Limits

- **Per minute:** 60 requests
- **Per hour:** 1000 requests
- **Timeout:** 30 seconds per request
- **Response:** 429 if exceeded

---

## 🚫 Safety Rules

**NEVER execute:**
- `rm -rf` (any deletion without confirm)
- `git reset --hard`
- `systemctl stop/disable`
- `sudo` commands
- Any destructive operation

**ALWAYS:**
- Check status first
- Ask for confirmation before dangerous operations
- Verify git status before committing
- Check available space before large operations
- Review error messages

---

## 🔧 Setup for Each Provider

### OpenAI ChatGPT

1. Create Custom GPT
2. Add action with endpoint: `http://137.131.156.17:5556`
3. Add header: `X-API-Key: sk-openai-default-key`
4. Use prompt from AGENT-PROMPTS.md

### Anthropic Claude

1. Add to Claude Desktop config or use via HTTP
2. Use header: `X-API-Key: sk-claude-default-key`
3. Copy prompt from AGENT-PROMPTS.md
4. Test with `/status` endpoint

### Google Gemini

1. Configure custom extension
2. Add header: `X-API-Key: sk-gemini-default-key`
3. Use prompt from AGENT-PROMPTS.md
4. Test connectivity

---

## 📊 Example Responses

### Status Response
```json
{
  "status": "online",
  "hostname": "instance-20260716",
  "user": "shopvivaliz",
  "timestamp": "2026-07-16T18:30:45Z",
  "services": {
    "shopvivaliz-sync": "running",
    "shopvivaliz-mcp": "running",
    "ssh": "running"
  }
}
```

### Command Execution Response
```json
{
  "output": "Build complete!\n",
  "error": "",
  "returncode": 0,
  "status": "success"
}
```

### Git Commit Response
```json
{
  "message": "feat: update from AI",
  "output": "[main abc1234] feat: update from AI\n 2 files changed, 5 insertions(+)",
  "status": "committed"
}
```

---

## 🐛 Troubleshooting

| Error | Solution |
|-------|----------|
| 401 Unauthorized | Check API key format |
| 429 Too Many Requests | Wait 60 seconds |
| 408 Command Timeout | Increase timeout param |
| 500 Server Error | Check /logs for details |
| Connection Refused | MCP server might be down |

---

## 📞 Full Documentation

For detailed information, see:
- **AGENT-PROMPTS.md** - Full prompts for each provider
- **MCP-PROVIDERS-SETUP.md** - Complete setup guide
- **CLAUDE-SETUP-COMPLETE.md** - System configuration

---

## ✅ Test Your Connection

Run this to verify access:

```bash
# 1. Test basic connectivity
curl http://137.131.156.17:5556/status

# 2. Test authentication
curl http://137.131.156.17:5556/status \
  -H "X-API-Key: sk-claude-default-key"

# 3. Test command execution
curl -X POST http://137.131.156.17:5556/exec \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"whoami"}'

# Expected output: shopvivaliz
```

---

**All set! Your AI agents now have full remote access! 🎉**
