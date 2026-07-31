#!/bin/bash

echo "╔════════════════════════════════════════════════════════════════════════════╗"
echo "║              VALIDAÇÃO COMPLETA - SHOPVIVALIZ                             ║"
echo "╚════════════════════════════════════════════════════════════════════════════╝"

SITE="https://shopvivaliz.com.br"
ERRORS=()
WARNINGS=()
SUCCESS=()

# ============================================================================
# TESTE 1: STATUS HTTP
# ============================================================================
echo -e "\n🔍 TESTE 1: Status HTTP das páginas principais..."

pages=(
  "/"
  "/sobre"
  "/contato"
  "/catalogo"
  "/carrinho"
  "/checkout.php"
)

for page in "${pages[@]}"; do
  status=$(curl -s -o /dev/null -w "%{http_code}" "$SITE$page" 2>/dev/null)
  if [ "$status" = "200" ]; then
    SUCCESS+=("✅ $SITE$page → 200 OK")
  else
    ERRORS+=("❌ $SITE$page → $status")
  fi
done

# ============================================================================
# TESTE 2: PRESENÇA DE ASSETS CRÍTICOS
# ============================================================================
echo -e "\n🔍 TESTE 2: Assets críticos..."

assets=(
  "/favicon.ico"
  "/images/favicon.svg"
  "/images/mercado-pago-logo.svg"
  "/images/logo-vivaliz.png"
  "/css/shopvivaliz-core-consolidated.css"
  "/js/main.js:optional"
)

for asset in "${assets[@]}"; do
  path="${asset%:*}"
  optional="${asset#*:}"

  status=$(curl -s -o /dev/null -w "%{http_code}" "$SITE$path" 2>/dev/null)

  if [ "$status" = "200" ]; then
    SUCCESS+=("✅ Asset $path → 200 OK")
  elif [ "$optional" = "optional" ]; then
    WARNINGS+=("⚠️  Asset $path → $status (opcional)")
  else
    ERRORS+=("❌ Asset $path → $status")
  fi
done

# ============================================================================
# TESTE 3: CONTEÚDO DA HOME
# ============================================================================
echo -e "\n🔍 TESTE 3: Conteúdo da home..."

html=$(curl -s "$SITE/" 2>/dev/null)

# Verificar links de navegação
if echo "$html" | grep -q 'href="/sobre'; then
  SUCCESS+=("✅ Link Sobre presente")
else
  ERRORS+=("❌ Link Sobre faltando")
fi

if echo "$html" | grep -q 'href="/contato'; then
  SUCCESS+=("✅ Link Contato presente")
else
  ERRORS+=("❌ Link Contato faltando")
fi

if echo "$html" | grep -q 'href="/catalogo'; then
  SUCCESS+=("✅ Link Catálogo presente")
else
  ERRORS+=("❌ Link Catálogo faltando")
fi

# Verificar logo Mercado Pago
if echo "$html" | grep -q 'mercado-pago'; then
  SUCCESS+=("✅ Logo Mercado Pago referenciado")
else
  WARNINGS+=("⚠️  Logo Mercado Pago não encontrado")
fi

# Verificar favicon
if echo "$html" | grep -q 'favicon'; then
  SUCCESS+=("✅ Favicon referenciado")
else
  ERRORS+=("❌ Favicon não referenciado")
fi

# ============================================================================
# TESTE 4: CHECKOUT
# ============================================================================
echo -e "\n🔍 TESTE 4: Página de checkout..."

checkout=$(curl -s "$SITE/checkout.php" 2>/dev/null)

if echo "$checkout" | grep -q 'payment'; then
  SUCCESS+=("✅ Seção de pagamento presente")
else
  WARNINGS+=("⚠️  Seção de pagamento não clara")
fi

if echo "$checkout" | grep -q 'customer_name'; then
  SUCCESS+=("✅ Form de cliente presente")
else
  ERRORS+=("❌ Form de cliente faltando")
fi

# ============================================================================
# TESTE 5: META TAGS SEO
# ============================================================================
echo -e "\n🔍 TESTE 5: SEO e Meta Tags..."

if echo "$html" | grep -q '<meta name="description"'; then
  SUCCESS+=("✅ Meta description presente")
else
  WARNINGS+=("⚠️  Meta description faltando")
fi

if echo "$html" | grep -q 'og:title'; then
  SUCCESS+=("✅ Open Graph tags presentes")
else
  WARNINGS+=("⚠️  Open Graph tags faltando")
fi

# ============================================================================
# TESTE 6: SEGURANÇA
# ============================================================================
echo -e "\n🔍 TESTE 6: Security Headers..."

headers=$(curl -s -I "$SITE/" 2>/dev/null)

if echo "$headers" | grep -iq "x-frame-options"; then
  SUCCESS+=("✅ X-Frame-Options presente")
else
  WARNINGS+=("⚠️  X-Frame-Options faltando")
fi

if echo "$headers" | grep -iq "x-content-type-options"; then
  SUCCESS+=("✅ X-Content-Type-Options presente")
else
  WARNINGS+=("⚠️  X-Content-Type-Options faltando")
fi

# ============================================================================
# TESTE 7: PERFORMANCE
# ============================================================================
echo -e "\n🔍 TESTE 7: Performance..."

# Tamanho CSS
css_size=$(curl -s "$SITE/css/shopvivaliz-core-consolidated.css" 2>/dev/null | wc -c)
if [ "$css_size" -lt 500000 ]; then
  SUCCESS+=("✅ CSS size OK (~${css_size}b)")
else
  WARNINGS+=("⚠️  CSS large (~${css_size}b, consider minify)")
fi

# ============================================================================
# TESTE 8: LINKS FUNCIONAIS
# ============================================================================
echo -e "\n🔍 TESTE 8: Links de navegação..."

links=(
  "Sobre:/sobre"
  "Contato:/contato"
  "Catálogo:/catalogo"
  "Carrinho:/carrinho"
)

for link in "${links[@]}"; do
  name="${link%:*}"
  path="${link#*:}"
  status=$(curl -s -o /dev/null -w "%{http_code}" "$SITE$path" 2>/dev/null)

  if [ "$status" = "200" ]; then
    SUCCESS+=("✅ Link $name ($path) → 200 OK")
  else
    ERRORS+=("❌ Link $name ($path) → $status")
  fi
done

# ============================================================================
# RELATÓRIO FINAL
# ============================================================================
echo ""
echo "╔════════════════════════════════════════════════════════════════════════════╗"
echo "║                           RELATÓRIO FINAL                                  ║"
echo "╚════════════════════════════════════════════════════════════════════════════╝"

echo ""
echo "✅ SUCESSOS: ${#SUCCESS[@]}"
for s in "${SUCCESS[@]}"; do
  echo "   $s"
done

echo ""
echo "⚠️  AVISOS: ${#WARNINGS[@]}"
for w in "${WARNINGS[@]}"; do
  echo "   $w"
done

echo ""
echo "❌ ERROS: ${#ERRORS[@]}"
for e in "${ERRORS[@]}"; do
  echo "   $e"
done

echo ""
echo "════════════════════════════════════════════════════════════════════════════"

TOTAL=$((${#SUCCESS[@]} + ${#ERRORS[@]} + ${#WARNINGS[@]}))
PASSING=$((${#SUCCESS[@]} + ${#WARNINGS[@]}))
PERCENTAGE=$((PASSING * 100 / TOTAL))

echo "STATUS: $PASSING/$TOTAL testes passando ($PERCENTAGE%)"
echo "════════════════════════════════════════════════════════════════════════════"

if [ ${#ERRORS[@]} -eq 0 ]; then
  echo "✅ SEM ERROS CRÍTICOS!"
  exit 0
else
  echo "❌ ${#ERRORS[@]} ERROS ENCONTRADOS - CORRIJA ANTES DE CONTINUAR"
  exit 1
fi
