#!/bin/bash
# Deploy Paralelo - FTP + Git Direct
# ===================================
# Tenta 2 estratégias simultaneamente:
# 1. FTP via curl (GitHub Actions)
# 2. Git push direto com retry

set -e

echo "=========================================="
echo "🚀 Deploy Paralelo - FTP + Git"
echo "=========================================="
echo ""

# Cores
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Configuração (via environment variables)
FTP_SERVER="${FTP_SERVER}"
FTP_USERNAME="${FTP_USERNAME}"
FTP_PASSWORD="${FTP_PASSWORD}"
FTP_REMOTE_DIR="${FTP_REMOTE_DIR}"
BRANCH="${1:-main}"

# Validar que variáveis estão configuradas
if [ -z "$FTP_SERVER" ] || [ -z "$FTP_USERNAME" ]; then
    echo "❌ Defina: FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_REMOTE_DIR"
    exit 1
fi

# Portas a tentar (em sequência)
FTP_PORTS=(21 2121 990)

# ============================================================================
# ESTRATÉGIA 1: FTP COM RETRY
# ============================================================================

try_ftp_deploy() {
    local port=$1

    echo -e "${YELLOW}[FTP] Tentando porta $port...${NC}"

    # Criar arquivo de lista para upload
    tar --exclude='.git' --exclude='.github' --exclude='.env*' \
        --exclude='node_modules' --exclude='vendor' \
        -czf /tmp/deploy-${RANDOM}.tar.gz . 2>/dev/null || true

    # Tentar conexão FTP
    if timeout 15 curl -sS \
        --user "${FTP_USERNAME}:${FTP_PASSWORD}" \
        --connect-timeout 10 \
        "ftp://${FTP_SERVER}:${port}/" \
        --list-only \
        -o /dev/null 2>&1; then

        echo -e "${GREEN}✅ Conexão FTP porta $port bem-sucedida!${NC}"

        # Upload via LFTP (mais robusto)
        if command -v lftp &> /dev/null; then
            echo "[FTP] Uploadando via lftp..."
            lftp -e "set ftp:ssl-allow no; \
                     set ftp:passive-mode yes; \
                     open -u ${FTP_USERNAME},${FTP_PASSWORD} ${FTP_SERVER}:${port}; \
                     mirror -R . ${FTP_REMOTE_DIR}; \
                     quit" 2>&1 | tail -5
            return 0
        fi
    fi

    return 1
}

# Tentar todas as portas
FTP_SUCCESS=0
for port in "${FTP_PORTS[@]}"; do
    if try_ftp_deploy $port; then
        FTP_SUCCESS=1
        break
    fi
    sleep 2
done

if [ $FTP_SUCCESS -eq 1 ]; then
    echo -e "${GREEN}✅ FTP Deploy bem-sucedido${NC}"
else
    echo -e "${YELLOW}⚠️ FTP falhou, continuando com Git...${NC}"
fi

echo ""

# ============================================================================
# ESTRATÉGIA 2: GIT PUSH DIRETO
# ============================================================================

echo -e "${YELLOW}[GIT] Push direto para $BRANCH...${NC}"

# Verificar git status
if ! git diff-index --quiet HEAD --; then
    echo "[GIT] Há mudanças não commitadas, criando commit..."
    git add .
    git commit -m "chore: auto-deploy $(date +%Y-%m-%d\ %H:%M:%S)" || true
fi

# Tentar push com retry
MAX_RETRIES=3
RETRY=0

while [ $RETRY -lt $MAX_RETRIES ]; do
    if git push origin $BRANCH --force-with-lease 2>&1 | grep -q "To https"; then
        echo -e "${GREEN}✅ Git Push bem-sucedido${NC}"
        GIT_SUCCESS=1
        break
    else
        RETRY=$((RETRY + 1))
        echo "[GIT] Retry $RETRY/$MAX_RETRIES..."
        sleep 3
    fi
done

if [ $RETRY -ge $MAX_RETRIES ]; then
    echo -e "${YELLOW}⚠️ Git push falhou${NC}"
fi

# ============================================================================
# RESUMO
# ============================================================================

echo ""
echo "=========================================="
echo "📊 Resultado do Deploy"
echo "=========================================="

if [ $FTP_SUCCESS -eq 1 ] || [ $GIT_SUCCESS -eq 1 ]; then
    echo -e "${GREEN}✅ Deploy bem-sucedido (FTP ou Git)${NC}"
    echo "    FTP: $([ $FTP_SUCCESS -eq 1 ] && echo '✅' || echo '❌')"
    echo "    GIT: $([ $GIT_SUCCESS -eq 1 ] && echo '✅' || echo '❌')"
    exit 0
else
    echo -e "${RED}❌ Deploy falhou (FTP e Git)${NC}"
    exit 1
fi
