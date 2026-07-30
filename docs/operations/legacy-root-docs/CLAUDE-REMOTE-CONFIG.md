# 🔧 Claude Remote Access Configuration

## Generated: 2026-07-16

This document contains the configuration needed to enable full remote access for Claude Code and Claude Chat.

---

## 📌 Connection Details

```
Host: 137.131.156.17
SSH User: shopvivaliz
SSH Port: 22
MCP Server: http://137.131.156.17:5556
MCP User: shopvivaliz
```

---

## 🔐 SSH Public Key (for Claude Code)

The public key is being retrieved from the remote machine and will be displayed in the GitHub Actions summary.

Once retrieved, add it to your local authorized_keys if needed.

---

## ⚙️ Configuration Steps

### Step 1: Configure Claude Code SSH Access

**Option A: Using SSH Config File**

Add to `~/.ssh/config`:

```
Host shopvivaliz-remote
    HostName 137.131.156.17
    User shopvivaliz
    Port 22
    IdentityFile ~/.ssh/claude_code_rsa
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null
```

Then in Claude Code settings:
```json
{
  "ssh": {
    "enable": true,
    "host": "shopvivaliz-remote"
  }
}
```

**Option B: Direct SSH Command**

```bash
ssh shopvivaliz@137.131.156.17
```

### Step 2: Configure Claude Chat MCP Access

In Claude Chat settings (claude.ai or Desktop):

1. Go to: **Settings → MCP Servers**
2. Add new server:

```json
{
  "name": "shopvivaliz-remote",
  "type": "http",
  "url": "http://137.131.156.17:5556",
  "protocol": "sse",
  "timeout": 30000
}
```

Or via CLI (if available):
```bash
curl http://137.131.156.17:5556/status
```

---

## ✅ Verification Commands

### Test SSH Access

```bash
# Test if SSH works
ssh shopvivaliz@137.131.156.17 "whoami"
# Expected output: shopvivaliz

# Test if git works remotely
ssh shopvivaliz@137.131.156.17 "cd ~/site-shopvivaliz && git status"
```

### Test MCP Server

```bash
# Check MCP Server status
curl -s http://137.131.156.17:5556/status | jq .

# Expected output:
{
  "status": "online",
  "user": "shopvivaliz",
  "host": "instance-...",
  "timestamp": "...",
  "services": {
    "shopvivaliz-sync": "running",
    "shopvivaliz-mcp": "running",
    "ssh": "running"
  }
}
```

### Get Service Logs

```bash
# MCP Server logs
curl -H "X-Lines: 50" http://137.131.156.17:5556/logs | jq .

# System health
curl http://137.131.156.17:5556/health | jq .

# Execute remote command
curl -X POST http://137.131.156.17:5556/exec \
  -H "Content-Type: application/json" \
  -d '{"cmd":"systemctl status shopvivaliz-sync"}'
```

---

## 🎯 What You Can Do Now

### With Claude Code (SSH Access)

```bash
# Direct shell access
ssh shopvivaliz@137.131.156.17

# Edit files
cd /home/shopvivaliz/site-shopvivaliz
nano file.txt

# Run commands
npm install
npm run build
python3 script.py

# Git operations
git log
git commit -m "message"
git push origin main
```

### With Claude Chat (MCP HTTP Access)

```bash
# Get system status
curl http://137.131.156.17:5556/status

# Check latest logs
curl http://137.131.156.17:5556/logs

# Verify health
curl http://137.131.156.17:5556/health

# Run admin commands
curl -X POST http://137.131.156.17:5556/exec \
  -d '{"cmd":"sudo systemctl restart shopvivaliz-sync"}'
```

---

## 🚀 Auto-Sync Features

The system includes automatic synchronization:

```
Every 30 seconds:
1. Git pulls from origin/main
2. Local repository is updated
3. No manual intervention needed
```

Check sync status:
```bash
ssh shopvivaliz@137.131.156.17 "systemctl status shopvivaliz-sync"
```

---

## 🔧 Troubleshooting

### Connection Refused

```bash
# Check if SSH is running
ssh shopvivaliz@137.131.156.17 "systemctl status ssh"

# Restart if needed
ssh shopvivaliz@137.131.156.17 "sudo systemctl restart ssh"
```

### MCP Server Not Responding

```bash
# Check MCP service
curl http://137.131.156.17:5556/health

# If down, restart:
ssh shopvivaliz@137.131.156.17 "sudo systemctl restart shopvivaliz-mcp"

# View logs:
ssh shopvivaliz@137.131.156.17 "sudo journalctl -u shopvivaliz-mcp -n 20"
```

### Permission Issues

```bash
# Check permissions
ssh shopvivaliz@137.131.156.17 "ls -la ~/.ssh/"

# Fix if needed
ssh shopvivaliz@137.131.156.17 "chmod 700 ~/.ssh && chmod 600 ~/.ssh/*"
```

---

## 📋 Services Running

- `shopvivaliz-mcp` (HTTP Server on port 5556)
- `shopvivaliz-sync` (Auto-sync every 30s)
- `ssh` (SSH Server on port 22)

All are configured to auto-start on boot.

---

## 🔐 Security Notes

- ✅ SSH uses key authentication (more secure than password)
- ✅ MCP Server runs as `shopvivaliz` user (not root)
- ✅ No credentials are hardcoded
- ✅ All connections are logged
- ✅ Services restart automatically on failure

---

*Configuration generated 2026-07-16*
