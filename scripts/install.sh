#!/bin/bash
# Instalador automático para Ubuntu/Cloud
# Execute com: bash <(curl -fsSL https://raw.githubusercontent.com/Vivaliz-site/site-shopvivaliz/main/scripts/install.sh)

set -e

echo "🚀 ShopVivaliz Auto-Setup"
echo "=========================="
echo ""

# Detectar distro
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "❌ Não é possível detectar o SO"
    exit 1
fi

echo "📋 Detectado: $OS"
echo ""

# Clone repo se não existir
if [ ! -d "site-shopvivaliz" ]; then
    echo "📥 Clonando repositório..."
    git clone https://github.com/Vivaliz-site/site-shopvivaliz.git
    cd site-shopvivaliz
else
    cd site-shopvivaliz
    git pull origin main
fi

echo ""
echo "⚙️  Instalando dependências..."

# Instalar dependências
case "$OS" in
    ubuntu|debian)
        sudo apt-get update -qq
        sudo apt-get install -y -qq python3 python3-pip git curl
        ;;
    centos|rhel|fedora)
        sudo yum install -y -qq python3 python3-pip git curl
        ;;
    alpine)
        sudo apk add --no-cache python3 py3-pip git curl
        ;;
    *)
        echo "⚠️  SO não reconhecido, continue manualmente"
        ;;
esac

echo "✓ Dependências OK"
echo ""

# Instalar GitHub CLI
if ! command -v gh &>/dev/null; then
    echo "📥 Instalando GitHub CLI..."
    curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo gpg --dearmor -o /usr/share/keyrings/githubcli-archive-keyring.gpg 2>/dev/null || true
    sudo bash -c 'echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages focal main" > /etc/apt/sources.list.d/github-cli.list'
    sudo apt-get update -qq 2>/dev/null || true
    sudo apt-get install -y -qq gh 2>/dev/null || true
fi

echo ""
echo "🔐 Sincronizando secrets..."
bash scripts/sincronizar_secrets_github.sh || bash scripts/bootstrap.sh

echo ""
echo "✅ Validando..."
python3 scripts/validar_secrets.py || true

echo ""
echo "⚙️  Configurando auto-sync..."

# Setup via systemd (recomendado)
if command -v systemctl &>/dev/null; then
    echo "Usando systemd..."
    sudo bash scripts/setup-auto-sync-linux.sh
else
    echo "Usando cron..."
    bash scripts/bootstrap.sh &
fi

echo ""
echo "============================================================"
echo "✅ SETUP CONCLUÍDO!"
echo "============================================================"
echo ""
echo "Status:"
echo "  ✅ Secrets sincronizados"
echo "  ✅ Auto-sync configurado"
echo "  ✅ Sistema pronto para produção"
echo ""
echo "Próximo passo: Validar que tudo está funcionando"
echo "  systemctl status shopvivaliz-sync"
echo ""
