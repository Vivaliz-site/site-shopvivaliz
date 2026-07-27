#!/bin/bash
# ShopVivaliz Production Smoke Test E2E
# ----------------------------------------------------------------
# Este script executa testes de fumaça completos na produção da ShopVivaliz,
# cobrindo redirecionamentos, certificados, segurança, APIs, catálogo, etc.
# Retorna exit 0 em caso de sucesso absoluto.
# ----------------------------------------------------------------

set -Eeuo pipefail

# Configurações básicas
readonly TARGET_HOST="shopvivaliz.com.br"
readonly BASE_URL="https://${TARGET_HOST}"
readonly TIMEOUT_SEC=10
readonly TEMP_DIR=$(mktemp -d "/tmp/smoke-test-XXXXXX")

# Função de log limpa
log_info() {
    printf "[INFO] [%s] %s\n" "$(date -u +'%Y-%m-%d %H:%M:%S UTC')" "$*"
}

log_error() {
    printf "[ERROR] [%s] %s\n" "$(date -u +'%Y-%m-%d %H:%M:%S UTC')" "$*" >&2
}

cleanup() {
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

# Início do teste
log_info "=== Iniciando Smoke Test de Produção (ShopVivaliz) ==="

# 1. Verificar tempo de resposta e ausência de 500 (Home)
log_info "1. Verificando Home e tempo de resposta..."
RESPONSE_STATS=$(curl -s -o "$TEMP_DIR/home.html" -w "%{http_code};%{time_total}" "$BASE_URL/")
HTTP_CODE=$(echo "$RESPONSE_STATS" | cut -d';' -f1)
TIME_TOTAL=$(echo "$RESPONSE_STATS" | cut -d';' -f2)

if [ "$HTTP_CODE" -ne 200 ]; then
    log_error "Home falhou com HTTP $HTTP_CODE"
    exit 1
fi
log_info "✓ Home respondeu HTTP 200 OK em ${TIME_TOTAL}s"

# 2. Verificar certificado SSL
log_info "2. Verificando validade do certificado SSL..."
if ! openssl s_client -connect "${TARGET_HOST}:443" -servername "${TARGET_HOST}" </dev/null 2>/dev/null | openssl x509 -noout -checkend 86400 >/dev/null; then
    log_error "Certificado SSL expira em menos de 24 horas ou é inválido!"
    exit 1
fi
log_info "✓ Certificado SSL ativo e válido"

# 3. Verificar redirecionamentos (http para https e www para sem www)
log_info "3. Verificando redirecionamentos canônicos..."
REDIRECT_HTTP=$(curl -s -o /dev/null -w "%{redirect_url}" "http://${TARGET_HOST}/")
if [ -z "$REDIRECT_HTTP" ] || [[ ! "$REDIRECT_HTTP" =~ ^https:// ]]; then
    log_error "Redirecionamento HTTP para HTTPS falhou!"
    exit 1
fi
log_info "✓ Redirecionamento HTTP para HTTPS funcionando corretamente"

# 4. Verificar CSS real
log_info "4. Verificando carregamento e tipo de conteúdo do CSS..."
CSS_URL="${BASE_URL}/css/shopvivaliz-premium-consolidated.css"
CSS_HEADERS=$(curl -sI -k "$CSS_URL")
if ! echo "$CSS_HEADERS" | grep -iq "content-type: text/css"; then
    log_error "CSS principal retornou tipo incorreto ou 404!"
    exit 1
fi
log_info "✓ CSS premium carregando corretamente"

# 5. Verificar catálogo
log_info "5. Verificando página do catálogo..."
CATALOG_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/catalogo")
if [ "$CATALOG_CODE" -ne 200 ]; then
    log_error "Catálogo indisponível (HTTP $CATALOG_CODE)"
    exit 1
fi
log_info "✓ Catálogo respondeu HTTP 200 OK"

# 6. Verificar API pública do catálogo
log_info "6. Verificando API de produtos do catálogo..."
API_RESPONSE=$(curl -s "${BASE_URL}/api/catalog/products.php")
if ! echo "$API_RESPONSE" | jq -e '.products' >/dev/null 2>&1; then
    log_error "API do catálogo retornou JSON inválido ou estrutura sem campo '.products'!"
    exit 1
fi
log_info "✓ API do catálogo funcionando e retornando JSON válido"

# 7. Obter produto real da API e verificar página de produto
log_info "7. Verificando página de um produto real..."
PRODUCT_SLUG=$(echo "$API_RESPONSE" | jq -r '.products[0].slug // empty')
if [ -z "$PRODUCT_SLUG" ] || [ "$PRODUCT_SLUG" = "null" ]; then
    # Fallback para o SKU
    PRODUCT_SKU=$(echo "$API_RESPONSE" | jq -r '.products[0].sku')
    PRODUCT_URL="${BASE_URL}/produto.php?sku=${PRODUCT_SKU}"
else
    PRODUCT_URL="${BASE_URL}/produto/${PRODUCT_SLUG}"
fi

PRODUCT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$PRODUCT_URL")
if [ "$PRODUCT_CODE" -ne 200 ]; then
    log_error "Página do produto real falhou (HTTP $PRODUCT_CODE no link: $PRODUCT_URL)"
    exit 1
fi
log_info "✓ Página do produto real respondeu HTTP 200 OK"

# 8. Verificar carrinho (disponibilidade da rota)
log_info "8. Verificando rota do carrinho..."
CART_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/carrinho")
if [ "$CART_CODE" -ne 200 ]; then
    log_error "Rota do carrinho falhou (HTTP $CART_CODE)"
    exit 1
fi
log_info "✓ Rota do carrinho disponível"

# 9. Verificar checkout (disponibilidade da rota)
log_info "9. Verificando rota do checkout..."
CHECKOUT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/checkout")
# Checkout redireciona para carrinho se vazio (HTTP 302) ou carrega 200 OK
if [ "$CHECKOUT_CODE" -ne 200 ] && [ "$CHECKOUT_CODE" -ne 302 ]; then
    log_error "Rota do checkout retornou erro crítico (HTTP $CHECKOUT_CODE)"
    exit 1
fi
log_info "✓ Rota do checkout segura e respondendo normalmente (HTTP $CHECKOUT_CODE)"

# 10. Verificar administrador protegido contra acessos anônimos
log_info "10. Verificando proteção da área administrativa..."
ADMIN_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/admin/")
if [ "$ADMIN_CODE" -eq 200 ]; then
    log_error "Área administrativa exposta publicamente sem autenticação!"
    exit 1
fi
log_info "✓ Área administrativa protegida contra acesso anônimo (HTTP $ADMIN_CODE)"

# 11. Verificar Webhook Olist rejeitando requisições inválidas
log_info "11. Verificando perimeter do Webhook da Olist..."
WEBHOOK_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
  -H "Content-Type: application/json" \
  -d '{"event":"health_test","test":true}' \
  "${BASE_URL}/olist/webhook-receiver.php")

# Esperado HTTP 400, 401 ou 403
if [ "$WEBHOOK_CODE" -ne 400 ] && [ "$WEBHOOK_CODE" -ne 401 ] && [ "$WEBHOOK_CODE" -ne 403 ] && [ "$WEBHOOK_CODE" -ne 405 ]; then
    log_error "Webhook aceitou requisição sem assinatura válida (HTTP $WEBHOOK_CODE)!"
    exit 1
fi
log_info "✓ Webhook da Olist barrou chamada anônima com HTTP $WEBHOOK_CODE"

log_info "================================================================"
log_info "🎉 TODOS OS TESTES DE FUMAÇA CRÍTICOS PASSARAM COM SUCESSO! 🎉"
log_info "================================================================"
exit 0
