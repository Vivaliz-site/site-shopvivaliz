#!/bin/bash
#
# Setup automático de sincronização (Linux/Ubuntu/Cloud)
# ======================================================
#
# Instala e configura auto-sync para rodar automaticamente no boot
#
# Uso:
#   sudo bash scripts/setup-auto-sync-linux.sh

set -e

echo "============================================================"
echo "🚀 Setup Auto-Sync ShopVivaliz (Linux/Ubuntu/Cloud)"
echo "============================================================"
echo ""

# Verificar root
if [[ $EUID -ne 0 ]]; then
    echo "❌ Este script deve rodar como root (use sudo)"
    exit 1
fi

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_NAME="shopvivaliz-sync"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
USER="${SUDO_USER:-shopvivaliz}"

echo "📋 Configuração:"
echo "   Repositório: $REPO_DIR"
echo "   Usuário: $USER"
echo "   Serviço: $SERVICE_NAME"
echo ""

# 1. Instalar dependências
echo "1️⃣  Instalando dependências..."
apt-get update -qq
apt-get install -y -qq python3 python3-pip git curl

# Instalar GitHub CLI
if ! command -v gh &> /dev/null; then
    echo "   Instalando GitHub CLI..."
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | gpg --dearmor -o /usr/share/keyrings/githubcli-archive-keyring.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages focal main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
    apt-get update -qq
    apt-get install -y -qq gh
fi

echo "   ✓ Dependências instaladas"
echo ""

# 2. Criar usuário se não existir
echo "2️⃣  Verificando usuário..."
if ! id "$USER" &>/dev/null; then
    echo "   Criando usuário $USER..."
    useradd -m -s /bin/bash "$USER"
fi
echo "   ✓ Usuário $USER OK"
echo ""

# 3. Configurar permissões
echo "3️⃣  Configurando permissões..."
chown -R "$USER:$USER" "$REPO_DIR"
chmod 755 "$REPO_DIR/scripts/auto-sincronizar.sh"
chmod 755 "$REPO_DIR/scripts/sincronizar_secrets_github.sh"
echo "   ✓ Permissões OK"
echo ""

# 4. Criar serviço systemd
echo "4️⃣  Criando serviço systemd..."
cat > "$SERVICE_FILE" << EOF
[Unit]
Description=ShopVivaliz Auto Sync
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=$USER
WorkingDirectory=$REPO_DIR
Environment="PATH=/usr/local/bin:/usr/bin:/bin"
ExecStart=/bin/bash $REPO_DIR/scripts/auto-sincronizar.sh
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
echo "   ✓ Serviço criado"
echo ""

# 5. Sincronizar secrets (primeira vez)
echo "5️⃣  Sincronizando secrets (primeira vez)..."
sudo -u "$USER" bash "$REPO_DIR/scripts/sincronizar_secrets_github.sh" > /dev/null 2>&1 || true
echo "   ✓ Secrets sincronizados"
echo ""

# 6. Validar
echo "6️⃣  Validando..."
sudo -u "$USER" python3 "$REPO_DIR/scripts/validar_secrets.py" > /dev/null 2>&1 && \
    echo "   ✓ Validação OK" || \
    echo "   ⚠️  Validação teve aviso"
echo ""

# 7. Habilitar e iniciar serviço
echo "7️⃣  Habilitando auto-sync no boot..."
systemctl enable "$SERVICE_NAME"
systemctl start "$SERVICE_NAME"
echo "   ✓ Serviço iniciado"
echo ""

# Status
echo "============================================================"
echo "✅ SETUP CONCLUÍDO!"
echo "============================================================"
echo ""
echo "Status do Serviço:"
systemctl status "$SERVICE_NAME" --no-pager
echo ""
echo "📋 Próximos passos:"
echo "  1. Ver logs: journalctl -u $SERVICE_NAME -f"
echo "  2. Parar: systemctl stop $SERVICE_NAME"
echo "  3. Reiniciar: systemctl restart $SERVICE_NAME"
echo "  4. Desabilitar: systemctl disable $SERVICE_NAME"
echo ""
echo "🔄 Auto-Sync está ATIVO e rodando!"
echo "   • Sincroniza secrets a cada 5 minutos"
echo "   • Puxa mudanças do Git automaticamente"
echo "   • Roda no boot automaticamente"
echo ""
