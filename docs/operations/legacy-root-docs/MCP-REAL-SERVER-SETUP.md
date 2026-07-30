# 🔗 Real MCP Server Setup - ChatGPT, Claude, Gemini

Complete setup for real MCP (Model Context Protocol) server compatible with all AI providers.

---

## ✅ What You Get

**Real MCP Server** implementing standard protocol:
- ✅ `initialize` - Server initialization  
- ✅ `tools/list` - List all available tools
- ✅ `tools/call` - Execute tools
- ✅ JSONRPC 2.0 over stdio
- ✅ MCP 2024-11-05 protocol compliant

**Available Tools:**
- `execute_command` - Run shell commands
- `git_status` - Check git status
- `git_commit` - Create commits
- `git_push` - Push to remote
- `file_read` - Read files
- `file_write` - Write files
- `service_status` - Monitor services
- `npm_build` - Build projects
- `system_health` - Health checks

---

## 🚀 Installation

### 1. Copy Server to Remote Machine

```bash
scp mcp-server-real.py shopvivaliz@137.131.156.17:/home/shopvivaliz/
```

### 2. Make Executable

```bash
ssh shopvivaliz@137.131.156.17 "chmod +x /home/shopvivaliz/mcp-server-real.py"
```

### 3. Create Systemd Service

```ini
[Unit]
Description=Real MCP Server for AI Providers
After=network.target

[Service]
Type=simple
User=shopvivaliz
ExecStart=/usr/bin/python3 /home/shopvivaliz/mcp-server-real.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### 4. Enable and Start

```bash
sudo systemctl enable mcp-real
sudo systemctl start mcp-real
```

---

## 🤖 Configure with ChatGPT

### In ChatGPT Custom GPT Settings:

1. **Go to:** Settings → My GPTs → Create new GPT
2. **Name:** ShopVivaliz Remote Machine
3. **Add capability:**
   - Enable: Code Interpreter
   - Enable: Web Browser
4. **Instructions:** Add from AGENT-PROMPTS.md
5. **Add MCP Server:**
   - Tool Type: MCP
   - Server: `localhost:5000` (for local) or via tunnel for remote

### For Remote Access:

Use **Secure MCP Tunnel** (OpenAI recommended):

```bash
# Install secure tunnel
pip install secure-mcp-tunnel

# Run tunnel to remote server
secure-mcp-tunnel --remote-host 137.131.156.17 \
                  --remote-port 9000 \
                  --local-port 5000
```

Then configure GPT to use `localhost:5000`

---

## 🧠 Configure with Claude

### Using Claude Desktop:

1. **Update Claude config file:**

```json
{
  "mcpServers": {
    "shopvivaliz": {
      "command": "python3",
      "args": ["/path/to/mcp-server-real.py"],
      "type": "stdio"
    }
  }
}
```

### Or use SSH:

```bash
ssh shopvivaliz@137.131.156.17 "/usr/bin/python3 /home/shopvivaliz/mcp-server-real.py"
```

### Via Claude API:

```python
from anthropic import Anthropic

client = Anthropic()
conversation_history = []

# Claude will use MCP server tools automatically
response = client.messages.create(
    model="claude-3-5-sonnet-20241022",
    max_tokens=4096,
    tools=[{
        "type": "mcp",
        "name": "shopvivaliz",
        "uri": "http://137.131.156.17:9000",  # Via tunnel
    }],
    messages=[{
        "role": "user",
        "content": "Check the git status and build the project"
    }]
)
```

---

## 🔍 Configure with Gemini

### Using Gemini Custom Extensions:

1. **Create extension:**
   - Go to: Google AI Studio → Create Extension
   - Type: Tool Use
   - Endpoint: Use secure tunnel

2. **Add Tools:**

```json
{
  "tools": [
    {
      "name": "execute_command",
      "description": "Execute remote command",
      "parameters": {
        "type": "object",
        "properties": {
          "cmd": {"type": "string"},
          "timeout": {"type": "integer"}
        }
      }
    }
  ]
}
```

### Via Gemini SDK:

```python
import anthropic_genai as genai

model = genai.GenerativeModel('gemini-pro')

# Use MCP tools
response = model.generate_content(
    "Build the project",
    tools=[{
        "type": "mcp",
        "name": "shopvivaliz",
        "uri": "http://137.131.156.17:9000"
    }]
)
```

---

## 🔐 Secure MCP Tunnel Setup

### For Remote Access from ChatGPT/Gemini:

```bash
# On remote server
pip install secure-mcp-tunnel

# Start server with tunnel
secure-mcp-tunnel --server "python3 /home/shopvivaliz/mcp-server-real.py" \
                  --port 9000 \
                  --token YOUR_SECURE_TOKEN
```

### Connect from AI Provider:

```
Endpoint: https://mcp-tunnel.yourdomain.com:9000
Token: YOUR_SECURE_TOKEN
```

---

## 📝 Test MCP Server

### 1. Direct JSONRPC Test

```bash
# Send initialize request
echo '{"method": "initialize", "params": {}}' | \
  python3 /home/shopvivaliz/mcp-server-real.py
```

### 2. List Tools

```bash
echo '{"method": "tools/list", "params": {}}' | \
  python3 /home/shopvivaliz/mcp-server-real.py
```

### 3. Execute Command

```bash
echo '{"method": "tools/call", "params": {"name": "execute_command", "arguments": {"cmd": "whoami"}}}' | \
  python3 /home/shopvivaliz/mcp-server-real.py
```

---

## 🎯 MCP Protocol Reference

### Message Format (JSONRPC 2.0)

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "execute_command",
    "arguments": {
      "cmd": "npm run build"
    }
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Build output..."
      }
    ],
    "isError": false
  }
}
```

---

## 🛡️ Security Considerations

### For Production:

1. **Use HTTPS only** (not HTTP)
2. **Enable authentication:**
   - API keys in headers
   - OAuth tokens
   - TLS client certificates

3. **Restrict access:**
   - Firewall rules
   - IP whitelist
   - Rate limiting

4. **Monitor usage:**
   - Log all operations
   - Alert on suspicious activity
   - Audit trail

5. **Disable dangerous commands:**
   - Block `rm -rf`, `git reset --hard`, etc
   - Require confirmation for destructive ops
   - Sandbox execution

---

## 📊 Example Conversations

### With ChatGPT

```
User: "Build the project and commit changes"

ChatGPT:
1. Calls: npm_build
2. Calls: git_status
3. Calls: git_commit with message
4. Calls: git_push
5. Reports: "Build complete and changes pushed"
```

### With Claude

```
User: "Check system health and restart services if needed"

Claude:
1. Calls: system_health
2. Calls: service_status for each service
3. Calls: Restarts if needed
4. Reports: "System health check complete"
```

### With Gemini

```
User: "Read config file and update it"

Gemini:
1. Calls: file_read with path
2. Calls: file_write with updated content
3. Reports: "Config updated successfully"
```

---

## 🚀 Deployment Checklist

- [ ] Server code deployed to remote
- [ ] Made executable (`chmod +x`)
- [ ] Systemd service created and enabled
- [ ] Server started and responding
- [ ] JSONRPC initialization working
- [ ] All 9 tools callable
- [ ] Logging configured
- [ ] Security rules in place
- [ ] HTTPS/tunnel configured (for remote)
- [ ] Tested with all 3 providers (GPT, Claude, Gemini)

---

## ❓ Troubleshooting

### Server not responding

```bash
# Check if running
systemctl status mcp-real

# View logs
journalctl -u mcp-real -n 50

# Restart
systemctl restart mcp-real
```

### JSONRPC errors

```bash
# Test with echo
echo '{"method": "initialize", "params": {}}' | python3 mcp-server-real.py
```

### Tool not found

```bash
# List all tools
echo '{"method": "tools/list", "params": {}}' | python3 mcp-server-real.py | jq .
```

---

## 📞 Support

- **Repository:** https://github.com/fredmourao-ai/site-shopvivaliz
- **Documentation:** AGENT-PROMPTS.md, QUICK-START-AGENTS.md
- **Test Script:** TEST-AGENT-ACCESS.sh
- **Issues:** GitHub Issues

---

**Now GPT, Claude, and Gemini can all access your machine via real MCP protocol! 🎉**
