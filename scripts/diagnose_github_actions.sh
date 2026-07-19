#!/bin/bash
# ==============================================================================
# Script de Diagnóstico Seguro - GitHub Actions & Deploy
# ==============================================================================
# Objetivo: Auditar workflows, secrets, configurações e validar sintaxe
# SEM fazer deploy, SEM expor credenciais, SEM alterar configurações
#
# Uso: bash scripts/diagnose_github_actions.sh
# ==============================================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Contadores
ERRORS=0
WARNINGS=0
INFO=0

# Funções de log
log_info() {
    echo -e "${BLUE}ℹ${NC} $1"
    ((INFO++))
}

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
    ((WARNINGS++))
}

log_error() {
    echo -e "${RED}✗${NC} $1"
    ((ERRORS++))
}

echo -e "${BLUE}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║      DIAGNÓSTICO DE GITHUB ACTIONS - SHOP VIVALIZ          ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# ==============================================================================
# 1. Validar Estrutura de Diretórios
# ==============================================================================
echo -e "${BLUE}▶ Verificando Estrutura de Diretórios${NC}"
echo ""

if [ -d ".github/workflows" ]; then
    log_success "Diretório .github/workflows encontrado"
    WORKFLOW_COUNT=$(find .github/workflows -name "*.yml" -o -name "*.yaml" | wc -l)
    log_info "Total de workflows: $WORKFLOW_COUNT"
else
    log_error "Diretório .github/workflows NÃO encontrado"
fi

if [ -d "api/agent" ]; then
    log_success "Diretório api/agent encontrado"
else
    log_warning "Diretório api/agent NÃO encontrado - watchdog pode falhar"
fi

if [ -d "scripts" ]; then
    log_success "Diretório scripts encontrado"
else
    log_warning "Diretório scripts NÃO encontrado"
fi

echo ""

# ==============================================================================
# 2. Auditar Secrets Referenciados nos Workflows
# ==============================================================================
echo -e "${BLUE}▶ Auditando Secrets Referenciados nos Workflows${NC}"
echo ""

log_info "Procurando por referências de secrets nos YAMLs..."

declare -A SECRETS_FOUND
declare -A WORKFLOWS_USING_SECRET

# Procurar todos os secrets referenciados
while IFS= read -r line; do
    # Extrair nomes de secrets (e.g., secrets.FTP_SERVER)
    if [[ $line =~ secrets\.([A-Z_]+) ]]; then
        secret_name="${BASH_REMATCH[1]}"
        SECRETS_FOUND[$secret_name]=1
    fi
done < <(grep -r "secrets\." .github/workflows --include="*.yml" --include="*.yaml" 2>/dev/null || true)

if [ ${#SECRETS_FOUND[@]} -eq 0 ]; then
    log_warning "Nenhum secret encontrado nos workflows"
else
    log_success "Secrets encontrados nos workflows:"
    for secret in $(echo "${!SECRETS_FOUND[@]}" | tr ' ' '\n' | sort); do
        echo "  • $secret"
    done
fi

echo ""

# ==============================================================================
# 3. Validar Arquivos YAML
# ==============================================================================
echo -e "${BLUE}▶ Validando Sintaxe dos Workflows YAML${NC}"
echo ""

YAML_VALID=0
YAML_INVALID=0

# Procurar comando yaml validator
if command -v yamllint &> /dev/null; then
    log_info "yamllint disponível - validando YAMLs..."
    while IFS= read -r yaml_file; do
        if yamllint "$yaml_file" > /dev/null 2>&1; then
            log_success "$(basename "$yaml_file")"
            ((YAML_VALID++))
        else
            log_error "$(basename "$yaml_file") - YAML inválido"
            ((YAML_INVALID++))
        fi
    done < <(find .github/workflows -maxdepth 1 \( -name '*.yml' -o -name '*.yaml' \) -type f | sort)
elif command -v python3 &> /dev/null; then
    log_info "python3 disponível - validando YAMLs..."
    while IFS= read -r yaml_file; do
        if python3 - <<PY
from pathlib import Path
import sys
try:
    import yaml
except Exception as exc:
    print(f"PyYAML indisponível: {exc}")
    sys.exit(2)
path = Path(r"""$yaml_file""")
try:
    yaml.safe_load(path.read_text(encoding="utf-8"))
except Exception as exc:
    print(f"{path.name}: YAML inválido")
    print(f"  {exc}")
    sys.exit(1)
PY
        then
            log_success "$(basename "$yaml_file")"
            ((YAML_VALID++))
        else
            log_error "$(basename "$yaml_file") - YAML inválido"
            ((YAML_INVALID++))
        fi
    done < <(find .github/workflows -maxdepth 1 \( -name '*.yml' -o -name '*.yaml' \) -type f | sort)
else
    log_warning "Nenhum validador YAML disponível (yamllint/python3)"
fi

echo ""

# ==============================================================================
# 4. Validar Sintaxe PHP
# ==============================================================================
echo -e "${BLUE}▶ Validando Sintaxe de Arquivos PHP${NC}"
echo ""

if command -v php &> /dev/null; then
    log_info "php disponível - validando arquivos..."
    PHP_VALID=0
    PHP_INVALID=0
    
    while IFS= read -r php_file; do
        if [ -f "$php_file" ]; then
            if php -l "$php_file" > /dev/null 2>&1; then
                log_success "$(basename "$php_file")"
                ((PHP_VALID++))
            else
                log_error "$(basename "$php_file") - Sintaxe inválida"
                ((PHP_INVALID++))
            fi
        fi
    done < <(find . -name "*.php" -not -path "./medusa/*" -not -path "./vendor/*" 2>/dev/null)
else
    log_warning "PHP não disponível - pulando validação de sintaxe"
fi

echo ""

# ==============================================================================
# 5. Validar Sintaxe Python
# ==============================================================================
echo -e "${BLUE}▶ Validando Sintaxe de Arquivos Python${NC}"
echo ""

if command -v python3 &> /dev/null; then
    log_info "python3 disponível - validando arquivos..."
    PY_VALID=0
    PY_INVALID=0
    
    while IFS= read -r py_file; do
        if [ -f "$py_file" ]; then
            if [ "$py_file" = "./scripts/olist-sync-manual.py" ]; then
                log_warning "$(basename "$py_file") - legado PowerShell, excluído da validação Python"
                continue
            fi
            if python3 -m py_compile "$py_file" 2>/dev/null; then
                log_success "$(basename "$py_file")"
                ((PY_VALID++))
            else
                log_error "$(basename "$py_file") - Sintaxe inválida"
                ((PY_INVALID++))
            fi
        fi
    done < <(find . -name "*.py" -not -path "./medusa/*" -not -path "./venv/*" 2>/dev/null)
else
    log_warning "Python3 não disponível - pulando validação de sintaxe"
fi

echo ""

# ==============================================================================
# 6. Verificar Conflitos Git
# ==============================================================================
echo -e "${BLUE}▶ Procurando por Conflitos Git${NC}"
echo ""

python3 - <<'PY'
from pathlib import Path
import sys

skip_dirs = {'.git', 'vendor', 'node_modules', 'venv'}
conflicts = []

for path in Path('.').rglob('*'):
    if not path.is_file():
        continue
    if any(part in skip_dirs for part in path.parts):
        continue
    try:
        lines = path.read_text(encoding='utf-8', errors='ignore').splitlines()
    except Exception:
        continue

    in_conflict = False
    seen_separator = False
    for line in lines:
        stripped = line.strip()
        if stripped.startswith('<<<<<<< '):
            in_conflict = True
            seen_separator = False
            continue
        if in_conflict and stripped == '=======':
            seen_separator = True
            continue
        if in_conflict and stripped.startswith('>>>>>>> '):
            if seen_separator:
                conflicts.append(str(path))
            in_conflict = False
            seen_separator = False

if conflicts:
    print("Conflitos Git encontrados!")
    for file_name in sorted(set(conflicts)):
        print(f"  • {file_name}")
    sys.exit(1)

print("Nenhum conflito Git encontrado")
PY
if [ $? -ne 0 ]; then
    log_error "Conflitos Git encontrados!"
else
    log_success "Nenhum conflito Git encontrado"
fi

echo ""

# ==============================================================================
# 7. Verificar Arquivos Sensíveis Commitados
# ==============================================================================
echo -e "${BLUE}▶ Procurando por Arquivos Sensíveis Commitados${NC}"
echo ""

SENSITIVE_PATTERNS=(
    ".env"
    ".env.*"
    "*.pem"
    "*.key"
    "id_rsa"
    "id_ed25519"
    "secrets.json"
    ".secrets"
)
FOUND_SENSITIVE=0

while IFS= read -r tracked_file; do
    [ -n "$tracked_file" ] || continue
    case "$tracked_file" in
        .github/*|docs/*|*.example)
            continue
            ;;
    esac

    base_name=$(basename "$tracked_file")
    for pattern in "${SENSITIVE_PATTERNS[@]}"; do
        case "$pattern" in
            .env)
                match=true
                [ "$base_name" = ".env" ] || match=false
                ;;
            .env.*)
                match=true
                case "$base_name" in
                    .env.*) ;;
                    *) match=false ;;
                esac
                ;;
            *.pem)
                match=true
                case "$base_name" in
                    *.pem) ;;
                    *) match=false ;;
                esac
                ;;
            *.key)
                match=true
                case "$base_name" in
                    *.key) ;;
                    *) match=false ;;
                esac
                ;;
            id_rsa|id_ed25519|secrets.json)
                match=true
                [ "$base_name" = "$pattern" ] || match=false
                ;;
            .secrets)
                match=true
                case "$tracked_file" in
                    .secrets|.secrets/*) ;;
                    *) match=false ;;
                esac
                ;;
        esac

        if [ "${match:-false}" = true ]; then
            log_error "Arquivo sensível encontrado: $tracked_file"
            echo "  • $tracked_file"
            ((FOUND_SENSITIVE++))
            break
        fi
    done
done < <(git ls-files)

if [ $FOUND_SENSITIVE -eq 0 ]; then
    log_success "Nenhum arquivo sensível encontrado em arquivos commitados"
fi

echo ""

# ==============================================================================
# 8. Validar Endpoints Esperados
# ==============================================================================
echo -e "${BLUE}▶ Validando Endpoints Esperados${NC}"
echo ""

# Watchdog endpoint
if [ -f "api/agent/autonomous-watchdog.php" ]; then
    log_success "api/agent/autonomous-watchdog.php encontrado"
else
    log_error "api/agent/autonomous-watchdog.php NÃO encontrado - workflow falhará"
fi

# Health endpoint
if [ -f "api/health.php" ]; then
    log_success "api/health.php encontrado"
else
    log_warning "api/health.php NÃO encontrado"
fi

# Catalog API
if [ -f "api/catalog/products.php" ]; then
    log_success "api/catalog/products.php encontrado"
else
    log_warning "api/catalog/products.php NÃO encontrado"
fi

echo ""

# ==============================================================================
# 9. Verificar Configuração de Arquivos de Exemplo
# ==============================================================================
echo -e "${BLUE}▶ Verificando Arquivos de Configuração Esperados${NC}"
echo ""

if [ -f ".env.example" ]; then
    log_success ".env.example encontrado"
    
    # Verificar se .env existe (mas não mostrar o conteúdo)
    if [ -f ".env" ]; then
        log_success ".env configurado"
    else
        log_warning ".env NÃO configurado - use: cp .env.example .env"
    fi
else
    log_error ".env.example NÃO encontrado"
fi

echo ""

# ==============================================================================
# 10. Status dos Principais Workflows
# ==============================================================================
echo -e "${BLUE}▶ Status dos Principais Workflows${NC}"
echo ""

declare -A MAIN_WORKFLOWS=(
    ["deploy.yml"]="Deploy via FTP"
    ["autonomous-watchdog.yml"]="Autonomous Watchdog Monitor"
    ["ci-autonomo-continuo.yml"]="CI Autônomo Contínuo"
)

for workflow in "${!MAIN_WORKFLOWS[@]}"; do
    if [ -f ".github/workflows/$workflow" ]; then
        log_success "${MAIN_WORKFLOWS[$workflow]} (.github/workflows/$workflow)"
        
        # Contar etapas
        step_count=$(grep -c "^\s*- name:" ".github/workflows/$workflow" || echo "0")
        log_info "  Contém $step_count etapas"
        
        # Checar se contém secrets
        if grep -q "secrets\." ".github/workflows/$workflow"; then
            log_info "  Usa secrets"
        fi
    else
        log_error "${MAIN_WORKFLOWS[$workflow]} NÃO encontrado"
    fi
done

echo ""

# ==============================================================================
# 11. Resumo Final
# ==============================================================================
echo -e "${BLUE}╔═══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                     RESUMO DO DIAGNÓSTICO                    ║${NC}"
echo -e "${BLUE}╚═══════════════════════════════════════════════════════════════╝${NC}"
echo ""

echo -e "✓ Informações:    ${GREEN}$INFO${NC}"
echo -e "⚠ Avisos:         ${YELLOW}$WARNINGS${NC}"
echo -e "✗ Erros:          ${RED}$ERRORS${NC}"
echo ""

# ==============================================================================
# 12. Recomendações
# ==============================================================================
echo -e "${BLUE}▶ Recomendações${NC}"
echo ""

if [ $ERRORS -gt 0 ]; then
    echo -e "${RED}AÇÃO NECESSÁRIA:${NC}"
    echo "  1. Verifique os erros acima"
    echo "  2. Configure secrets no GitHub:"
    echo "     Settings → Secrets and variables → Actions"
    echo "  3. Reexecute este diagnóstico após configurar"
    echo ""
fi

echo "PRÓXIMOS PASSOS:"
echo "  1. Verifique os Secrets obrigatórios no GitHub:"
echo "     • FTP_SERVER (ou FTP_HOST como fallback)"
echo "     • FTP_USERNAME (ou FTP_USER como fallback)"
echo "     • FTP_PASSWORD (ou FTP_PASS como fallback)"
echo "     • FTP_PORT"
echo "     • FTP_REMOTE_DIR (ou FTP_TARGET_DIR/FTP_PATH como fallback)"
echo "     • SHOPVIVALIZ_AGENT_KEY (ou AGENT_KEY/WATCHDOG_AGENT_KEY/AUTONOMOUS_AGENT_KEY como fallback)"
echo ""
echo "  2. Configure o arquivo .env local:"
echo "     $ cp .env.example .env"
echo "     $ # Edite .env com os valores reais"
echo ""
echo "  3. Para habilitar DEBUG nos workflows:"
echo "     Crie um novo secret: ACTIONS_STEP_DEBUG = true"
echo ""
echo "  4. Para testar o watchdog manualmente:"
echo "     $ curl -f https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php"
echo ""
echo "  5. Para reexecutar um workflow com debug:"
echo "     GitHub Actions → Seu Workflow → Re-run jobs → Enable debug logging"
echo ""

# ==============================================================================
# 13. Arquivo de Relatório
# ==============================================================================

mkdir -p reports

REPORT_FILE="reports/github-actions-diagnostic-$(date +%Y%m%d_%H%M%S).txt"

{
    echo "RELATÓRIO DE DIAGNÓSTICO - GITHUB ACTIONS"
    echo "Data: $(date)"
    echo "Repositório: $(git config --get remote.origin.url 2>/dev/null || echo 'não configurado')"
    echo "Branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'não configurado')"
    echo ""
    echo "RESUMO:"
    echo "  • Informações: $INFO"
    echo "  • Avisos: $WARNINGS"
    echo "  • Erros: $ERRORS"
    echo ""
    echo "SECRETS ENCONTRADOS NOS WORKFLOWS:"
    for secret in $(echo "${!SECRETS_FOUND[@]}" | tr ' ' '\n' | sort); do
        echo "  • $secret"
    done
    echo ""
    echo "ENDPOINTS ESPERADOS:"
    echo "  • api/agent/autonomous-watchdog.php: $([ -f 'api/agent/autonomous-watchdog.php' ] && echo 'OK' || echo 'MISSING')"
    echo "  • api/health.php: $([ -f 'api/health.php' ] && echo 'OK' || echo 'MISSING')"
    echo "  • api/catalog/products.php: $([ -f 'api/catalog/products.php' ] && echo 'OK' || echo 'MISSING')"
    echo ""
    echo "WORKFLOWS PRINCIPAIS:"
    for workflow in "${!MAIN_WORKFLOWS[@]}"; do
        if [ -f ".github/workflows/$workflow" ]; then
            echo "  • $workflow: OK"
        else
            echo "  • $workflow: MISSING"
        fi
    done
    echo ""
    echo "ARQUIVOS DE CONFIGURAÇÃO:"
    echo "  • .env.example: $([ -f '.env.example' ] && echo 'OK' || echo 'MISSING')"
    echo "  • .env: $([ -f '.env' ] && echo 'CONFIGURADO' || echo 'NÃO CONFIGURADO')"
} > "$REPORT_FILE"

log_info "Relatório salvo em: $REPORT_FILE"

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
echo "Diagnóstico concluído! Verifique os resultados acima."
echo ""

exit $ERRORS
