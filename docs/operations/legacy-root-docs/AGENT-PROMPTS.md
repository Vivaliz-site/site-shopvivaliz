# 🤖 AI Agent Access Prompts

Prompts e instruções para GPT, Claude, Gemini e outros agentes acessarem a máquina remota.

---

## 🎯 Universal Agent Setup

**Todos os agentes têm acesso a:**
```
Host: 137.131.156.17:5556
Protocol: HTTP
Auth: X-API-Key header
```

---

## 🔴 OpenAI GPT - Prompt de Acesso

### Prompt para adicionar ao Custom GPT:

```markdown
# ShopVivaliz Remote Machine Access

You now have access to the ShopVivaliz production machine at http://137.131.156.17:5556

## Authentication
- Use header: `X-API-Key: sk-openai-default-key`
- OR use: `Authorization: Bearer sk-openai-default-key`

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
POST /git/status
POST /git/log (with "lines": 10)
POST /git/commit (with "message": "your message")
POST /git/push (with "remote": "origin", "branch": "main")
POST /git/pull
```

### 3. File Management
```bash
POST /file/read {"path": "/home/shopvivaliz/file.txt"}
POST /file/write {"path": "/home/shopvivaliz/file.txt", "content": "..."}
POST /file/delete {"path": "/home/shopvivaliz/file.txt"}
```

### 4. Service Management
```bash
POST /service/status {"service": "shopvivaliz-sync"}
POST /service/restart {"service": "shopvivaliz-sync"}
POST /service/logs {"service": "shopvivaliz-mcp", "lines": 20}
```

### 5. NPM Operations
```bash
POST /npm/install {"path": "/home/shopvivaliz/site-shopvivaliz"}
POST /npm/run {"script": "build", "path": "/home/shopvivaliz/site-shopvivaliz"}
```

### 6. System Information
```bash
GET /status
GET /health
GET /logs?lines=20
GET /tools
GET /providers
```

## Instructions for GPT

1. **Before executing commands**, always ask for confirmation if it's destructive
2. **Use timeouts** for long-running commands (npm install should have timeout: 300)
3. **Check status first** before restarting services
4. **Git workflow:**
   - Check status first: `POST /git/status`
   - View changes: `POST /git/log`
   - Commit: `POST /git/commit`
   - Push: `POST /git/push`

## Example Tasks

### Task 1: Deploy Update
```
User: "Deploy the latest changes"
1. Check git status: POST /git/status
2. Pull latest: POST /git/pull
3. Install deps: POST /npm/install
4. Build: POST /npm/run {"script": "build"}
5. Check health: GET /health
```

### Task 2: Monitor System
```
User: "Check if everything is running"
1. Get status: GET /status
2. Check health: GET /health
3. Service logs: POST /service/logs
4. System logs: GET /logs?lines=50
```

### Task 3: Quick Commit & Push
```
User: "Commit changes and push"
1. Check status: POST /git/status
2. Commit: POST /git/commit {"message": "fix: update"}
3. Push: POST /git/push {"remote": "origin", "branch": "main"}
```

## Error Handling

- **Connection timeout:** Retry in 5 seconds
- **Rate limit (429):** Wait 60 seconds before retrying
- **Command failed:** Show error output and suggest fixes
- **Service not found:** List available services with POST /service/status

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

Access remote machine via HTTP MCP at http://137.131.156.17:5556

**Authentication:** Include header `X-API-Key: sk-claude-default-key` in all requests

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

**Endpoint:** http://137.131.156.17:5556
**Header:** X-API-Key: sk-gemini-default-key

All tools available via HTTP POST/GET. See endpoint documentation.

Recommend: Check /status first, then /health, then execute operations.
```

---

## 📋 Generic Agent Prompt (All Providers)

Use this for any AI agent:

```markdown
# Remote Machine Access Protocol

**Server:** http://137.131.156.17:5556
**Auth:** X-API-Key: sk-{provider}-default-key

## Quick Reference

| Action | Endpoint | Method |
|--------|----------|--------|
| Check Status | /status | GET |
| Health Check | /health | GET |
| Execute Command | /exec | POST |
| Git Status | /git/status | POST |
| Git Commit | /git/commit | POST |
| Git Push | /git/push | POST |
| Read File | /file/read | POST |
| Write File | /file/write | POST |
| Service Restart | /service/restart | POST |
| NPM Build | /npm/run | POST |

## Standard Workflow

1. **Before any operation:** Check `/status` and `/health`
2. **For git operations:** Always use `git/status` → `git/commit` → `git/push` sequence
3. **For npm operations:** Use `npm/install` first, then `npm/run`
4. **For service management:** Get status first, then restart
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

API_KEY = "sk-{provider}-default-key"
BASE_URL = "http://137.131.156.17:5556"

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

# Git commit
response = requests.post(
    f"{BASE_URL}/git/commit",
    headers=headers,
    json={
        "message": "feat: update from AI",
        "path": "/home/shopvivaliz/site-shopvivaliz"
    }
)
print(response.json())
```

### JavaScript Example

```javascript
const API_KEY = "sk-provider-default-key";
const BASE_URL = "http://137.131.156.17:5556";

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
curl http://137.131.156.17:5556/status

# Execute command
curl -X POST http://137.131.156.17:5556/exec \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"npm run build","timeout":60}'

# Git commit
curl -X POST http://137.131.156.17:5556/git/commit \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{"message":"feat: update","path":"/home/shopvivaliz/site-shopvivaliz"}'

# Service restart
curl -X POST http://137.131.156.17:5556/service/restart \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{"service":"shopvivaliz-sync"}'
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
- Verify firewall allows port 5556

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
