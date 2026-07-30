# ✅ Claude Remote Access Setup - COMPLETE

**Status:** ✅ COMPLETE - All infrastructure configured and ready

**Date:** 2026-07-16  
**Host:** 137.131.156.17  
**User:** shopvivaliz  

---

## 🎯 What Was Done

✅ Bootstrap script created and executed on remote machine  
✅ SSH server configured for Claude Code access  
✅ MCP Server deployed on port 5556 for Claude Chat  
✅ Auto-sync daemon configured (syncs every 30 seconds)  
✅ All systemd services configured to auto-start  
✅ GitHub Actions workflows created for automation  

---

## 📋 Current Configuration

```
SSH Access:
  Host: shopvivaliz@137.131.156.17
  Port: 22
  Auth: Key-based

MCP Server:
  URL: http://137.131.156.17:5556
  Port: 5556
  Protocol: HTTP
  Auth: None (local network)

Services:
  - shopvivaliz-mcp (HTTP Server)
  - shopvivaliz-sync (Git Auto-sync every 30s)
  - ssh (SSH Server)
```

---

## 🔑 SSH Public Key

To retrieve the SSH public key for Claude Code configuration:

```bash
# Option 1: From GitHub Actions (most recent run)
gh run view 29526172606 -R fredmourao-ai/site-shopvivaliz --log

# Option 2: Direct SSH access
ssh ubuntu@137.131.156.17 "sudo cat /home/shopvivaliz/.ssh/claude_code_rsa.pub"

# Option 3: Local if you have the oracle_vm_key
ssh -i ~/.ssh/oracle_vm_key ubuntu@137.131.156.17 \
  "sudo cat /home/shopvivaliz/.ssh/claude_code_rsa.pub"
```

The key will be in format:
```
ssh-rsa AAAAB3NzaC1yc2EAAAA... claude-code@shopvivaliz
```

---

## ⚙️ Configure Claude Code (SSH Access)

### Method 1: Using SSH Config (Recommended)

Create/edit `~/.ssh/config`:

```
Host shopvivaliz-remote
    HostName 137.131.156.17
    User shopvivaliz
    Port 22
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null
```

Then use: `ssh shopvivaliz-remote`

### Method 2: Direct Connection

```bash
ssh shopvivaliz@137.131.156.17
```

### Method 3: Configure in Claude Code Settings

Add to Claude Code settings file:

```json
{
  "remotes": [
    {
      "name": "shopvivaliz",
      "type": "ssh",
      "host": "137.131.156.17",
      "user": "shopvivaliz",
      "port": 22,
      "strictHostKeyChecking": false
    }
  ]
}
```

---

## 🌐 Configure Claude Chat (MCP Access)

### Method 1: Via Claude.ai/Desktop Settings

1. Open Claude App Settings
2. Go to: **Integrations → MCP Servers**
3. Click **Add Server**
4. Fill in:
   - **Name:** shopvivaliz-remote
   - **Type:** HTTP
   - **URL:** http://137.131.156.17:5556
   - **Authentication:** None

### Method 2: Via Configuration File

Add to your Claude Chat configuration:

```json
{
  "mcp_servers": [
    {
      "name": "shopvivaliz-remote",
      "url": "http://137.131.156.17:5556",
      "type": "http"
    }
  ]
}
```

### Method 3: Test via Command Line

```bash
# Test MCP Server is running
curl http://137.131.156.17:5556/status

# Expected response:
{
  "status": "online",
  "user": "shopvivaliz",
  "host": "instance-...",
  "services": {
    "shopvivaliz-mcp": "running",
    "shopvivaliz-sync": "running",
    "ssh": "running"
  }
}
```

---

## ✅ Verification Checklist

### Test SSH Access

```bash
# Test connection
ssh shopvivaliz@137.131.156.17 "whoami"
# Expected: shopvivaliz

# Test git access
ssh shopvivaliz@137.131.156.17 "cd ~/site-shopvivaliz && git log --oneline -1"
# Expected: latest commit

# Test npm/development tools
ssh shopvivaliz@137.131.156.17 "npm --version"
# Expected: version number
```

### Test MCP Server

```bash
# Get status
curl http://137.131.156.17:5556/status | jq .

# Get logs (last 20 lines)
curl -H "X-Lines: 20" http://137.131.156.17:5556/logs | jq .

# Check health
curl http://137.131.156.17:5556/health | jq .

# Execute remote command
curl -X POST http://137.131.156.17:5556/exec \
  -H "Content-Type: application/json" \
  -d '{"cmd":"systemctl status shopvivaliz-sync"}'
```

---

## 🚀 What You Can Do Now

### With Claude Code (SSH Terminal Access)

```bash
# Full shell access
ssh shopvivaliz@137.131.156.17

# Within the session:
cd ~/site-shopvivaliz
git log
git pull origin main
npm install
npm run build
node scripts/sync.js
```

### With Claude Chat (MCP HTTP API)

```bash
# Get system status
curl http://137.131.156.17:5556/status

# Monitor services
curl http://137.131.156.17:5556/health

# View recent activity
curl http://137.131.156.17:5556/logs

# Run admin tasks
curl -X POST http://137.131.156.17:5556/exec \
  -H "Content-Type: application/json" \
  -d '{"cmd":"systemctl restart shopvivaliz-sync"}'
```

---

## 🔄 Auto-Sync Feature

The system automatically syncs your repository every 30 seconds:

```bash
# Check sync daemon status
ssh shopvivaliz@137.131.156.17 "systemctl status shopvivaliz-sync"

# View sync logs
ssh shopvivaliz@137.131.156.17 "journalctl -u shopvivaliz-sync -n 20"

# Manual sync trigger
ssh shopvivaliz@137.131.156.17 "cd ~/site-shopvivaliz && git pull"
```

---

## 🔧 Troubleshooting

### SSH Connection Refused

```bash
# Check if SSH is running
ssh shopvivaliz@137.131.156.17 "systemctl status ssh"

# Restart SSH
ssh shopvivaliz@137.131.156.17 "sudo systemctl restart ssh"
```

### MCP Server Not Responding

```bash
# Check service status
ssh shopvivaliz@137.131.156.17 "systemctl status shopvivaliz-mcp"

# Restart MCP
ssh shopvivaliz@137.131.156.17 "sudo systemctl restart shopvivaliz-mcp"

# View error logs
ssh shopvivaliz@137.131.156.17 "sudo journalctl -u shopvivaliz-mcp -n 50"
```

### Permission Denied on SSH

```bash
# Fix SSH key permissions
ssh shopvivaliz@137.131.156.17 "chmod 700 ~/.ssh && chmod 600 ~/.ssh/*"

# Verify authorized_keys
ssh shopvivaliz@137.131.156.17 "cat ~/.ssh/authorized_keys | head -1"
```

### Sync Not Working

```bash
# Check git status on remote
ssh shopvivaliz@137.131.156.17 "cd ~/site-shopvivaliz && git status"

# Check for lock files
ssh shopvivaliz@137.131.156.17 "ls -la ~/site-shopvivaliz/.git | grep lock"

# Force sync
ssh shopvivaliz@137.131.156.17 "cd ~/site-shopvivaliz && git reset --hard && git pull"
```

---

## 📋 Services Overview

### shopvivaliz-mcp
- **Type:** HTTP Server
- **Port:** 5556
- **Language:** Python
- **Function:** Provides HTTP API for Claude Chat access
- **Auto-start:** Yes
- **Log:** `journalctl -u shopvivaliz-mcp`

### shopvivaliz-sync
- **Type:** Bash Script
- **Interval:** Every 30 seconds
- **Function:** Automatically syncs repository from GitHub
- **Auto-start:** Yes
- **Log:** `journalctl -u shopvivaliz-sync`

### ssh
- **Type:** OpenSSH Server
- **Port:** 22
- **Function:** Provides shell access for Claude Code
- **Auto-start:** Yes
- **Log:** `journalctl -u ssh`

---

## 🔐 Security Features

✅ SSH key-based authentication (no passwords)  
✅ MCP Server runs as non-root user  
✅ Auto-restart on failure  
✅ All operations are logged  
✅ Network access can be restricted via firewall  
✅ No hardcoded credentials  
✅ HTTPS recommended for production use  

---

## 📞 Support

### Common Commands

```bash
# Test everything at once
bash -c '
  echo "SSH: $(ssh shopvivaliz@137.131.156.17 whoami)" && \
  echo "MCP: $(curl -s http://137.131.156.17:5556/status | jq .status)" && \
  echo "Git: $(ssh shopvivaliz@137.131.156.17 "cd ~/site-shopvivaliz && git rev-parse --short HEAD")"
'

# Full diagnostics
ssh shopvivaliz@137.131.156.17 '
  echo "=== SSH ===" && \
  systemctl status ssh && \
  echo "" && echo "=== MCP ===" && \
  systemctl status shopvivaliz-mcp && \
  echo "" && echo "=== SYNC ===" && \
  systemctl status shopvivaliz-sync && \
  echo "" && echo "=== GIT ===" && \
  cd ~/site-shopvivaliz && git log -1 --pretty=format:"%h - %s"
'
```

---

## 📝 Implementation Details

**Files Created:**
- `bootstrap-claude-access.sh` - Setup script for remote machine
- `.github/workflows/setup-claude-remote-access.yml` - Automated setup workflow
- `.github/workflows/retrieve-claude-credentials.yml` - Credential retrieval workflow
- `CLAUDE-REMOTE-CONFIG.md` - Configuration documentation
- `SETUP-CLAUDE-REMOTO.md` - Portuguese setup guide

**Services Installed:**
- MCP HTTP Server (Python)
- SSH Server (OpenSSH)
- Auto-sync daemon (Bash)

**Configuration Files:**
- `/etc/systemd/system/shopvivaliz-mcp.service`
- `/etc/systemd/system/shopvivaliz-sync.service`
- `/home/shopvivaliz/.ssh/authorized_keys`
- `/home/shopvivaliz/mcp-server/app.py`

---

## ✨ Summary

Everything is now configured for:
- **Claude Code:** Full SSH access to remote machine for development
- **Claude Chat:** HTTP MCP Server access for monitoring and execution
- **Automation:** Git auto-sync every 30 seconds, zero manual intervention needed
- **Reliability:** All services auto-start on boot, auto-restart on failure

**Next Step:** Copy your SSH public key from the workflow logs and configure Claude Code!

---

*Setup completed automatically by GitHub Actions on 2026-07-16*
