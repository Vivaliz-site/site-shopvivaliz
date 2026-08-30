#!/bin/bash
# Bootstrap Script - Configure acesso para ferramentas de agente.
# Execute somente no host explicitamente designado para esse papel; nao use endpoints aposentados.
# Uso: sudo bash bootstrap-claude-access.sh

set -e

echo "Iniciando Bootstrap para acesso de agente..."
echo "=================================="

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

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

echo -e "${YELLOW}[1/10] Criando usuario shopvivaliz...${NC}"
if id "$SHOPVIVALIZ_USER" &>/dev/null; then
    echo "Usuario ja existe"
else
    useradd -m -s /bin/bash -d "$SHOPVIVALIZ_HOME" "$SHOPVIVALIZ_USER" || true
    echo "Usuario criado"
fi

echo -e "${YELLOW}[2/10] Configurando diretorio SSH...${NC}"
mkdir -p "$SHOPVIVALIZ_SSH_DIR"
chmod 700 "$SHOPVIVALIZ_SSH_DIR"
chown "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_SSH_DIR"

echo -e "${YELLOW}[3/10] Gerando chave SSH para acesso local...${NC}"
if [ ! -f "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa" ]; then
    ssh-keygen -t rsa -b 4096 -f "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa" -N "" -C "claude-code@shopvivaliz"
    chmod 600 "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa"
    chmod 644 "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa.pub"
    chown "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa"*
fi

echo -e "${YELLOW}[4/10] Adicionando chave ao authorized_keys...${NC}"
touch "$SHOPVIVALIZ_SSH_DIR/authorized_keys"
grep -qxF "$(cat "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa.pub")" "$SHOPVIVALIZ_SSH_DIR/authorized_keys" || cat "$SHOPVIVALIZ_SSH_DIR/claude_code_rsa.pub" >> "$SHOPVIVALIZ_SSH_DIR/authorized_keys"
chmod 600 "$SHOPVIVALIZ_SSH_DIR/authorized_keys"
chown "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_SSH_DIR/authorized_keys"

echo -e "${YELLOW}[5/10] Instalando dependencias...${NC}"
apt-get update -qq
apt-get install -y -qq python3 python3-pip curl git jq 2>/dev/null

echo -e "${YELLOW}[6/10] Criando MCP Server legado...${NC}"
mkdir -p "$SHOPVIVALIZ_HOME/mcp-server"
cat > "$SHOPVIVALIZ_HOME/mcp-server/app.py" << 'MCPEOF'
#!/usr/bin/env python3
import json
import subprocess
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse
import os

class MCPHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlparse(self.path).path
        if path == '/status':
            self._send_json(200, {"status": "online", "user": os.getenv("USER", "shopvivaliz"), "host": subprocess.check_output("hostname", text=True).strip()})
        elif path == '/health':
            self._send_json(200, {"healthy": True, "mcp": "running"})
        else:
            self._send_json(404, {"error": "Not found"})

    def _send_json(self, code, data):
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def log_message(self, format, *args):
        pass

if __name__ == '__main__':
    server = HTTPServer(('127.0.0.1', 5556), MCPHandler)
    print("Legacy MCP health server listening on localhost:5556")
    server.serve_forever()
MCPEOF
chmod +x "$SHOPVIVALIZ_HOME/mcp-server/app.py"
chown -R "$SHOPVIVALIZ_USER:$SHOPVIVALIZ_USER" "$SHOPVIVALIZ_HOME/mcp-server"

echo -e "${YELLOW}[7/10] Criando systemd service para MCP local...${NC}"
cat > "/etc/systemd/system/shopvivaliz-mcp.service" << 'SVCEOF'
[Unit]
Description=ShopVivaliz local legacy MCP health service
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

echo -e "${YELLOW}[8/10] Mantendo auto-sync legado desabilitado por seguranca...${NC}"
systemctl disable --now shopvivaliz-sync.service 2>/dev/null || true

echo -e "${YELLOW}[9/10] Validando SSH...${NC}"
systemctl enable ssh
systemctl restart ssh

echo -e "${YELLOW}[10/10] Validando MCP local...${NC}"
sleep 2
curl -fsS http://127.0.0.1:${MCP_PORT}/status >/dev/null

echo -e "${GREEN}Bootstrap concluido. MCP legado limitado a localhost; use o Desktop Commander oficial para acesso remoto.${NC}"
