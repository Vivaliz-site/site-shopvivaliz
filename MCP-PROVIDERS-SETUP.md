# 🌍 Universal MCP Server - Multi-Provider Setup

Configuração para GPT (OpenAI), Claude, Gemini e qualquer provedor IA acessar a máquina remota.

---

## 📋 Informações de Conexão

```
Host: 137.131.156.17
Port: 5556
Protocol: HTTP
Endpoints: /status, /health, /exec, /git/*, /file/*, /service/*, /npm/*
```

---

## 🤖 OpenAI GPT (ChatGPT, GPT-4)

### Option 1: Via Custom Headers

```bash
curl -X POST http://137.131.156.17:5556/exec \
  -H "Authorization: Bearer sk-openai-default-key" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"npm run build","timeout":60}'
```

### Option 2: Via X-API-Key Header

```bash
curl -X POST http://137.131.156.17:5556/exec \
  -H "X-API-Key: sk-openai-default-key" \
  -H "Content-Type: application/json" \
  -d '{"cmd":"git status"}'
```

### Integration via Custom GPT

1. **Create a Custom GPT in ChatGPT**
2. **Add Action with these settings:**

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "Remote Machine API",
    "version": "1.0.0"
  },
  "servers": [
    {
      "url": "http://137.131.156.17:5556"
    }
  ],
  "paths": {
    "/exec": {
      "post": {
        "summary": "Execute remote command",
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "cmd": {"type": "string"},
                  "timeout": {"type": "integer"}
                }
              }
            }
          }
        },
        "responses": {
          "200": {"description": "Command executed"}
        }
      }
    },
    "/status": {
      "get": {
        "summary": "Get system status",
        "responses": {
          "200": {"description": "System status"}
        }
      }
    }
  }
}
```

**Add authentication:**
- Type: `API Key`
- In: `header`
- Header name: `Authorization`
- Value: `Bearer sk-openai-default-key`

---

## 🧠 Claude (Anthropic)

### Option 1: Direct HTTP Request

```bash
curl -X POST http://137.131.156.17:5556/git/commit \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "feat: update from Claude",
    "path": "/home/shopvivaliz/site-shopvivaliz"
  }'
```

### Option 2: Via Claude SDK

```python
import anthropic
import httpx

client = anthropic.Anthropic()

# Execute remote command
response = httpx.post(
    "http://137.131.156.17:5556/exec",
    headers={"X-API-Key": "sk-claude-default-key"},
    json={"cmd": "npm run build", "timeout": 60}
)

# Use response in Claude
message = client.messages.create(
    model="claude-3-5-sonnet-20241022",
    max_tokens=1024,
    messages=[
        {
            "role": "user",
            "content": f"Build output: {response.json()['output']}"
        }
    ]
)
```

### Option 3: Claude Web Interface

Add to your prompt:
```
Access remote machine at http://137.131.156.17:5556
API Key: sk-claude-default-key
Use X-API-Key header for all requests
```

---

## 🔍 Google Gemini

### Via Gemini API

```python
import google.generativeai as genai
import requests

genai.configure(api_key="YOUR_GEMINI_API_KEY")
model = genai.GenerativeModel("gemini-pro")

# Call remote machine
response = requests.post(
    "http://137.131.156.17:5556/status",
    headers={"X-API-Key": "sk-gemini-default-key"}
)

# Use in Gemini
prompt = f"""
Analyze this system status and provide recommendations:
{response.json()}
"""

gemini_response = model.generate_content(prompt)
print(gemini_response.text)
```

### Gemini Extension Setup

1. Create a Gemini Extension
2. Add endpoint: `http://137.131.156.17:5556`
3. Add header: `X-API-Key: sk-gemini-default-key`

---

## 🎯 Universal API Keys

Set environment variables:

```bash
# OpenAI
export MCP_OPENAI_KEY="sk-openai-default-key"

# Claude
export MCP_CLAUDE_KEY="sk-claude-default-key"

# Gemini
export MCP_GEMINI_KEY="sk-gemini-default-key"

# Default (all providers)
export MCP_DEFAULT_KEY="sk-default-universal-key"
```

Or in systemd service:

```ini
[Service]
Environment="MCP_OPENAI_KEY=sk-openai-default-key"
Environment="MCP_CLAUDE_KEY=sk-claude-default-key"
Environment="MCP_GEMINI_KEY=sk-gemini-default-key"
```

---

## 📍 Available Endpoints

### Read-Only (No Auth Required for GET)

- `GET /status` - System status
- `GET /health` - System health
- `GET /logs?lines=20` - System logs
- `GET /tools` - Available tools
- `GET /providers` - Supported providers

### Execute (Auth Required)

**Git Operations:**
- `POST /git/status` - Check git status
- `POST /git/log` - View git log
- `POST /git/commit` - Create commit
- `POST /git/push` - Push to remote
- `POST /git/pull` - Pull from remote

**File Operations:**
- `POST /file/read` - Read file
- `POST /file/write` - Write file
- `POST /file/delete` - Delete file

**Command Execution:**
- `POST /exec` - Execute shell command

**Service Management:**
- `POST /service/status` - Check service
- `POST /service/restart` - Restart service
- `POST /service/logs` - Service logs

**NPM Operations:**
- `POST /npm/install` - npm install
- `POST /npm/run` - npm run script

---

## 🔐 Security Features

- ✅ API Key authentication
- ✅ Rate limiting (60 req/min, 1000 req/hour per provider)
- ✅ CORS enabled
- ✅ Command timeout protection
- ✅ Provider detection
- ✅ Request logging
- ✅ HMAC signature verification

---

## 📊 Rate Limiting

- **Per Minute:** 60 requests
- **Per Hour:** 1000 requests
- **Timeout:** 30 seconds (configurable)

---

## 📝 Example Requests

### 1. Get System Status

```bash
curl http://137.131.156.17:5556/status
```

### 2. Execute Command (with auth)

```bash
curl -X POST http://137.131.156.17:5556/exec \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{"cmd": "npm run build", "timeout": 60}'
```

### 3. Git Commit (with auth)

```bash
curl -X POST http://137.131.156.17:5556/git/commit \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "feat: update from AI",
    "path": "/home/shopvivaliz/site-shopvivaliz"
  }'
```

### 4. List Tools

```bash
curl http://137.131.156.17:5556/tools | jq .
```

### 5. NPM Run Script

```bash
curl -X POST http://137.131.156.17:5556/npm/run \
  -H "X-API-Key: sk-claude-default-key" \
  -H "Content-Type: application/json" \
  -d '{"script": "build", "path": "/home/shopvivaliz/site-shopvivaliz"}'
```

---

## 🚀 Deployment

### 1. Copy to Remote Machine

```bash
scp mcp-server-universal.py shopvivaliz@137.131.156.17:/home/shopvivaliz/
```

### 2. Create systemd Service

```ini
[Unit]
Description=Universal MCP Server for AI Providers
After=network.target

[Service]
Type=simple
User=shopvivaliz
WorkingDirectory=/home/shopvivaliz
Environment="MCP_CLAUDE_KEY=sk-claude-default-key"
Environment="MCP_OPENAI_KEY=sk-openai-default-key"
Environment="MCP_GEMINI_KEY=sk-gemini-default-key"
ExecStart=/usr/bin/python3 /home/shopvivaliz/mcp-server-universal.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### 3. Enable Service

```bash
sudo systemctl daemon-reload
sudo systemctl enable mcp-universal
sudo systemctl start mcp-universal
```

### 4. Verify

```bash
curl http://137.131.156.17:5556/status
```

---

## 📋 Troubleshooting

### Connection Refused
```bash
# Check if service is running
sudo systemctl status mcp-universal

# Restart service
sudo systemctl restart mcp-universal
```

### Authentication Failed
```bash
# Check logs
sudo journalctl -u mcp-universal -n 20

# Verify API key
export MCP_CLAUDE_KEY="your-key-here"
```

### Rate Limit Exceeded
- Wait 60 seconds or 1 hour depending on limit
- Request is logged with provider info

---

## 📞 Support

All three providers (GPT, Claude, Gemini) now have full access to:
- Execute commands remotely
- Manage git repositories
- Read/write/delete files
- Control services
- Run npm scripts
- Monitor system health

**All without needing direct credentials or SSH access!** 🎉
