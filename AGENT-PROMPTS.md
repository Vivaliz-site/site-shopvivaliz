# 🤖 AI Agent Access Prompts

Prompts e instruções para GPT, Claude, Gemini e outros agentes acessarem a máquina remota.

---

## 🎯 Universal Agent Setup

**Todos os agentes têm acesso a:**
```
Host: http://127.0.0.1:5560
LAN:  http://192.168.0.16:5560
Protocol: HTTP
Auth: X-API-Key header
Key:  storage/remote-access/api-key.txt
```

---

## 🔴 OpenAI GPT - Prompt de Acesso

### Prompt para adicionar ao Custom GPT:

```markdown
# ShopVivaliz Remote Machine Access

You now have access to the ShopVivaliz remote gateway at http://127.0.0.1:5560

## Authentication
- Read the key from `storage/remote-access/api-key.txt`
- Send it as `X-API-Key`

## Available Tools

### 1. Execute Commands
```bash
POST /exec
{
  "cmd": "npm run build",
  "timeout": 60
}
```

### 2. Git Operations
```bash
POST /mcp/tool/execute_git_command
{
  "params": {
    "command": "status",
    "timeout": 30
  }
}
```

### 3. File Management
```bash
POST /mcp/tool/read_file
POST /mcp/tool/write_file
```

### 4. Browser Control
```bash
POST /browser/open
POST /browser/click
POST /browser/fill
POST /browser/type
POST /browser/screenshot
```

### 5. System Information
```bash
GET /status
GET /health
GET /mcp/tools
```

## Instructions for GPT

1. **Before executing commands**, always ask for confirmation if it's destructive
2. **Use timeouts** for long-running commands (npm install should have timeout: 300)
3. **Check status first** before restarting services
4. **Git workflow:**
   - Check status first: `POST /mcp/tool/execute_git_command` with `status`
   - View changes: `POST /mcp/tool/execute_git_command` with `diff`
   - Commit: `POST /mcp/tool/execute_git_command` with `commit ...`
   - Push: `POST /mcp/tool/execute_git_command` with `push`

## Example Tasks

### Task 1: Deploy Update
```
User: "Deploy the latest changes"
1. Check git status: POST /mcp/tool/execute_git_command {"params":{"command":"status"}}
2. Pull latest: POST /mcp/tool/execute_git_command {"params":{"command":"pull --ff-only origin main"}}
3. Build: POST /exec {"cmd":"npm run build","timeout":300}
5. Check health: GET /health
```

### Task 2: Monitor System
```
User: "Check if everything is running"
1. Get status: GET /status
2. Check health: GET /health
3. Logs: POST /mcp/tool/get_logs {"params":{"log_type":"mcp","lines":50}}
4. Browser status: POST /browser/status
```

### Task 3: Quick Commit & Push
```
User: "Commit changes and push"
1. Check status: POST /mcp/tool/execute_git_command {"params":{"command":"status"}}
2. Commit: POST /mcp/tool/execute_git_command {"params":{"command":"commit -m \"fix: update\""}}
3. Push: POST /mcp/tool/execute_git_command {"params":{"command":"push origin HEAD"}}
```

## Error Handling

- **Connection timeout:** Retry in 5 seconds
- **Rate limit (429):** Wait 60 seconds before retrying
- **Command failed:** Show error output and suggest fixes
- **Tool not found:** List available tools with GET /mcp/tools

## Safety Rules

🚫 **NEVER execute without asking:**
- `rm -rf` anything
- `git reset --hard`
- `systemctl stop/disable`
- Any command with `sudo`

✅ **ALWAYS check first:**
- Current git status before committing
- Service status before restarting
- Available disk space before large operations

## Rate Limits

- Per minute: 60 requests
- Per hour: 1000 requests
- Command timeout: 30 seconds (configurable)

---

## 🧠 Claude - Prompt de Acesso

Use this prompt in Claude to enable remote access:

```markdown
# ShopVivaliz Machine Access

Access the machine through the local Claude Desktop MCP bridge:
- Bridge: `C:\site-shopvivaliz\scripts\shopvivaliz-stdio-mcp.py`
- Gateway: `http://127.0.0.1:5560`

**Authentication:** read `storage/remote-access/api-key.txt` and send `X-API-Key`

**Endpoints:** (see OpenAI section above for detailed endpoint reference)

**Workflow:**
1. Check status before operations
2. Confirm destructive operations
3. Handle timeouts gracefully
4. Log all actions taken
```

---

## 🔍 Gemini - Prompt de Acesso

```markdown
# Remote Machine Integration

**Endpoint:** http://127.0.0.1:5560
**Header:** X-API-Key from `storage/remote-access/api-key.txt`

All tools available via HTTP POST/GET. See endpoint documentation.

Recommend: Check /status first, then /health, then execute operations.
```

---

## 📋 Generic Agent Prompt (All Providers)

Use this for any AI agent:

```markdown
# Remote Machine Access Protocol

**Server:** http://127.0.0.1:5560
**Auth:** X-API-Key from `storage/remote-access/api-key.txt`

## Quick Reference

| Action | Endpoint | Method |
|--------|----------|--------|
| Check Status | /status | GET |
| Health Check | /health | GET |
| Execute Command | /exec | POST |
| Git Status | /mcp/tool/execute_git_command | POST |
| Git Commit | /mcp/tool/execute_git_command | POST |
| Git Push | /mcp/tool/execute_git_command | POST |
| Read File | /file/read | POST |
| Write File | /file/write | POST |
| Browser Open | /browser/open | POST |
| Browser Screenshot | /browser/screenshot | POST |

## Standard Workflow

1. **Before any operation:** Check `/status` and `/health`
2. **For git operations:** Always use `/mcp/tool/execute_git_command`
3. **For browser work:** Use `/browser/*` tools
4. **For command work:** Use `/exec` with timeouts
5. **After operations:** Verify with `/status` or `/health`

## Error Responses

- 401: Invalid API key
- 429: Rate limit exceeded (wait 60 seconds)
- 408: Command timeout
- 500: Server error (check logs with GET /logs)

## Best Practices

✅ Always ask before destructive operations
✅ Use appropriate timeouts for long operations
✅ Check status before and after changes
✅ Log all operations for audit trail
✅ Handle rate limits gracefully

❌ Never execute rm -rf, git reset --hard, or similar dangerous commands
❌ Don't ignore errors - always read error messages
❌ Don't spam requests - respect rate limits
```

---

## 🚀 How Agents Should Call the API

### Python Example (All Agents)

```python
import requests
import json

with open("storage/remote-access/api-key.txt", "r", encoding="utf-8") as f:
    API_KEY = f.read().strip()
BASE_URL = "http://127.0.0.1:5560"

headers = {
    "X-API-Key": API_KEY,
    "Content-Type": "application/json"
}

# Check status
response = requests.get(f"{BASE_URL}/status", headers=headers)
print(response.json())

# Execute command
response = requests.post(
    f"{BASE_URL}/exec",
    headers=headers,
    json={
        "cmd": "npm run build",
        "timeout": 60
    }
)
print(response.json())

# Git command
response = requests.post(
    f"{BASE_URL}/mcp/tool/execute_git_command",
    headers=headers,
    json={
        "params": {
            "command": "status"
        }
    }
)
print(response.json())
```

### JavaScript Example

```javascript
const API_KEY = "<read storage/remote-access/api-key.txt>";
const BASE_URL = "http://127.0.0.1:5560";

const headers = {
  "X-API-Key": API_KEY,
  "Content-Type": "application/json"
};

// Get status
fetch(`${BASE_URL}/status`, { headers })
  .then(r => r.json())
  .then(data => console.log(data));

// Execute command
fetch(`${BASE_URL}/exec`, {
  method: "POST",
  headers,
  body: JSON.stringify({
    cmd: "npm run build",
    timeout: 60
  })
})
  .then(r => r.json())
  .then(data => console.log(data));
```

### cURL Examples

```bash
# Check status
curl http://127.0.0.1:5560/status

# Execute command
curl -X POST http://127.0.0.1:5560/exec \
  -H "X-API-Key: $(Get-Content storage/remote-access/api-key.txt -Raw)" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"npm run build","timeout":60}'

# Git command
curl -X POST http://127.0.0.1:5560/mcp/tool/execute_git_command \
  -H "X-API-Key: $(Get-Content storage/remote-access/api-key.txt -Raw)" \
  -H "Content-Type: application/json" \
  -d '{"params":{"command":"status"}}'
```

---

## 📍 Providers and Their Default Keys

| Provider | Key | Header |
|----------|-----|--------|
| OpenAI GPT | sk-openai-default-key | Authorization: Bearer or X-API-Key |
| Claude | sk-claude-default-key | X-API-Key |
| Gemini | sk-gemini-default-key | X-API-Key |
| Custom | sk-default-universal-key | X-API-Key |

---

## 🔗 Full Integration Checklist

- [ ] Read this entire guide
- [ ] Understand authentication method for your provider
- [ ] Test `/status` endpoint first
- [ ] Test `/health` endpoint
- [ ] Review rate limits (60/min, 1000/hour)
- [ ] Review safety rules (no destructive commands)
- [ ] Implement error handling
- [ ] Add logging for audit trail
- [ ] Test with a simple command (e.g., `whoami`)
- [ ] Document your integration

---

## ❓ Troubleshooting

### "401 Unauthorized"
- Check API key is correct
- Verify header name is `X-API-Key`
- Ensure header value starts with `sk-`

### "429 Too Many Requests"
- Rate limit exceeded
- Wait 60 seconds
- Reduce request frequency

### "Connection refused"
- MCP server might be down
- Check `/health` endpoint
- Verify firewall allows port 5560

### "Command timeout"
- Operation took too long
- Increase timeout parameter
- Split large operations into smaller ones

### "404 Not found"
- Check endpoint path
- Verify HTTP method (GET vs POST)
- Use `/tools` to see available endpoints

---

## 📞 Support Resources

- **Status endpoint:** GET /status
- **Health check:** GET /health
- **Available tools:** GET /tools
- **System logs:** GET /logs?lines=50
- **Service logs:** POST /service/logs

All agents should have full access with appropriate error handling!
