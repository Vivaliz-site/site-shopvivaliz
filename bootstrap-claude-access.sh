#!/bin/bash
# Bootstrap Script - Configure acesso total para Claude Code e Claude Chat
# Execute ISTO na máquina remota (137.131.156.17) como root ou com sudo
# Uso: bash bootstrap-claude-access.sh

set -e

echo "🚀 Iniciando Bootstrap para Claude Code + Chat Access..."
echo "=================================="

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Verificar se é root
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}Este script deve ser executado como root${NC}"
   echo "Use: sudo bash bootstrap-claude-access.sh"
   exit 1
fi

SHOPVIVALIZ_USER="shopvivaliz"
SHOPVIVALIZ_HOME="/home/$SHOPVIVALIZ_USER"
SHOPVIVALIZ_SSH_DIR="$SHOPVIVALIZ_HOME/.ssh"
MCP_PORT="5556"
SYNC_PORT="5555"

echo -e "${YELLOW}[1/10] Criando usuário shopvivaliz...${NC}"
if id "$SHOPVIVALIZ_USER" &>/dev/null; then
    echo "✓ Usuário já existe"
else
    useradd -m -s /bin/bash -d "$SHOPVIVALIZ_HOME" "$SHOPVIVALIZ_USER" || true
    echo "✓ Usuário criado"
fi

echo -e "${YELLOW}[2/10] Configurando diretório SSH...${NC}"
mkdir -p "$SHOPVIVALIZ_SSH_DIR"
chmod 700 "$SHOPVIVALIZ_SSH_DIR"
chown "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_SSH_DIR"
echo "✓ Diretório SSH pronto"

echo -e "${YELLOW}[3/10] Gerando chave SSH para Claude Code...${NC}"
if [ ! -f "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa" ]; then
    ssh-keygen -t rsa -b 4096 -f "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa" -N "" -C "claude-code@shopvivaliz"
    chmod 600 "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa"
    chmod 644 "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa.pub"
    chown "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa"*
    echo "✓ Chave SSH gerada"
else
    echo "✓ Chave SSH já existe"
fi

echo -e "${YELLOW}[4/10] Adicionando chave ao authorized_keys...${NC}"
cat "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa.pub" >> "$SHOPVIVALIZ_SSH_DIR/authorized_keys" || true
chmod 600 "$SHOPVIVALIZ_SSH_DIR/authorized_keys"
chown "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_SSH_DIR/authorized_keys"
echo "✓ authorized_keys configurado"

echo -e "${YELLOW}[5/10] Instalando dependências...${NC}"
apt-get update -qq
apt-get install -y -qq python3 python3-pip curl git jq 2>/dev/null
echo "✓ Dependências instaladas"

echo -e "${YELLOW}[6/10] Criando MCP Server para Claude Chat...${NC}"
mkdir -p "$SHOPVIVALIZ_HOME/mcp-server"
cat > "$SHOPVIVALIZ_HOME/mcp-server/app.py" << 'MCPEOF'
#!/usr/bin/env python3
import json
import subprocess
import sys
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse
import os

class MCPHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlparse(self.path).path

        if path == '/status':
            status = {
                "status": "online",
                "user": os.getenv("USER", "shopvivaliz"),
                "host": subprocess.check_output("hostname", text=True).strip(),
                "timestamp": subprocess.check_output("date -u", shell=True, text=True).strip(),
                "services": self._get_services_status()
            }
            self._send_json(200, status)

        elif path == '/logs':
            lines = int(self.headers.get('X-Lines', '20'))
            logs = self._get_logs(lines)
            self._send_json(200, {"logs": logs})

        elif path == '/health':
            health = {
                "healthy": True,
                "ssh": self._check_ssh(),
                "sync": self._check_sync(),
                "mcp": "running"
            }
            self._send_json(200, health)

        else:
            self._send_json(404, {"error": "Not found"})

    def do_POST(self):
        path = urlparse(self.path).path

        if path == '/exec':
            content_length = int(self.headers.get('Content-Length', 0))
            body = json.loads(self.rfile.read(content_length))
            cmd = body.get('cmd', '')

            try:
                result = subprocess.check_output(cmd, shell=True, stderr=subprocess.STDOUT, text=True, timeout=30)
                self._send_json(200, {"output": result, "status": "success"})
            except subprocess.TimeoutExpired:
                self._send_json(500, {"error": "Command timeout"})
            except Exception as e:
                self._send_json(500, {"error": str(e)})
        else:
            self._send_json(404, {"error": "Not found"})

    def _send_json(self, code, data):
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def _get_services_status(self):
        services = ['shopvivaliz-sync', 'shopvivaliz-mcp', 'ssh']
        status = {}
        for svc in services:
            try:
                result = subprocess.run(['systemctl', 'is-active', svc], capture_output=True, text=True)
                status[svc] = 'running' if result.returncode == 0 else 'stopped'
            except:
                status[svc] = 'unknown'
        return status

    def _get_logs(self, lines):
        try:
            result = subprocess.check_output(f"journalctl -n {lines} -u shopvivaliz-sync", shell=True, text=True)
            return result.split('\n')
        except:
            return []

    def _check_ssh(self):
        try:
            subprocess.run(['systemctl', 'is-active', 'ssh'], check=True, capture_output=True)
            return True
        except:
            return False

    def _check_sync(self):
        try:
            subprocess.run(['systemctl', 'is-active', 'shopvivaliz-sync'], check=True, capture_output=True)
            return True
        except:
            return False

    def log_message(self, format, *args):
        pass  # Silence logs

if __name__ == '__main__':
    server = HTTPServer(('0.0.0.0', 5556), MCPHandler)
    print("MCP Server listening on :5556")
    server.serve_forever()
MCPEOF

chmod +x "$SHOPVIVALIZ_HOME/mcp-server/app.py"
chown -R "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_HOME/mcp-server"
echo "✓ MCP Server criado"

echo -e "${YELLOW}[7/10] Criando systemd service para MCP...${NC}"
cat > "/etc/systemd/system/shopvivaliz-mcp.service" << 'SVCEOF'
[Unit]
Description=ShopVivaliz MCP Server for Claude Chat
After=network.target

[Service]
Type=simple
User=shopvivaliz
WorkingDirectory=/home/shopvivaliz/mcp-server
ExecStart=/usr/bin/python3 /home/shopvivaliz/mcp-server/app.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
SVCEOF

systemctl daemon-reload
systemctl enable shopvivaliz-mcp
systemctl restart shopvivaliz-mcp
echo "✓ MCP service ativado"

echo -e "${YELLOW}[8/10] Criando sync service...${NC}"
cat > "/etc/systemd/system/shopvivaliz-sync.service" << 'SYNCEOF'
[Unit]
Description=ShopVivaliz Auto-Sync
After=network.target

[Service]
Type=simple
User=shopvivaliz
WorkingDirectory=/home/shopvivaliz
ExecStart=/bin/bash -c 'while true; do cd /home/shopvivaliz && git pull origin main 2>/dev/null; sleep 30; done'
Restart=always

[Install]
WantedBy=multi-user.target
SYNCEOF

systemctl daemon-reload
systemctl enable shopvivaliz-sync
systemctl restart shopvivaliz-sync
echo "✓ Sync service ativado"

echo -e "${YELLOW}[9/10] Configurando SSH...${NC}"
# Garantir que SSH está rodando
systemctl enable ssh
systemctl restart ssh
echo "✓ SSH ativado"

echo -e "${YELLOW}[10/10] Validando acesso...${NC}"
sleep 2

# Testar MCP
if curl -s http://localhost:5556/status > /dev/null 2>&1; then
    echo "✓ MCP Server respondendo"
else
    echo "⚠ MCP Server pode estar demorando"
fi

# Testar SSH
if systemctl is-active --quiet ssh; then
    echo "✓ SSH rodando"
else
    echo "✗ SSH não está rodando"
fi

echo ""
echo -e "${GREEN}=================================="
echo "✅ BOOTSTRAP COMPLETO!"
echo "=================================="
echo ""
echo "📋 CREDENCIAIS E INFORMAÇÕES:"
echo ""
echo "Usuário: $SHOPVIVALIZ_USER"
echo "Host: $(hostname -I | awk '{print $1}')"
echo "SSH Port: 22"
echo "MCP Port: 5556"
echo ""
echo "Chave SSH Pública para Claude Code:"
echo "---"
cat "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa.pub"
echo "---"
echo ""
echo "Próximos passos:"
echo "1. Compartilhe a chave SSH pública acima com Claude Code"
echo "2. Configure Claude Chat para acessar: http://$(hostname -I | awk '{print $1}'):5556/status"
echo "3. Verifique: curl http://localhost:5556/status"
echo ""
echo -e "${NC}"
