#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8099}"
TMPDIR=$(mktemp -d) || TMPDIR="/tmp"
PHP_SERVER_PID=""

cleanup() {
  local exit_code=$?
  if [ $exit_code -ne 0 ]; then
    echo "=== ERROR: Script exited with code $exit_code ==="
    echo "=== PHP Server Logs ==="
    [ -f "$TMPDIR/shopvivaliz-php-server.log" ] && cat "$TMPDIR/shopvivaliz-php-server.log" || true
  fi
  if [[ -n "${PHP_SERVER_PID}" ]] && kill -0 "${PHP_SERVER_PID}" 2>/dev/null; then
    kill "${PHP_SERVER_PID}" || true
  fi
  rm -rf "$TMPDIR" 2>/dev/null || true
}
trap cleanup EXIT

echo "Checking prerequisites..."
if ! command -v php &> /dev/null; then
  echo "ERROR: PHP not found"
  exit 1
fi

if lsof -i :8099 >/dev/null 2>&1; then
  echo "Killing existing process on port 8099..."
  lsof -i :8099 | awk 'NR!=1 {print $2}' | xargs kill -9 2>/dev/null || true
  sleep 1
fi

echo "Starting PHP built-in server on 127.0.0.1:8099..."
php -S 127.0.0.1:8099 -t . >"$TMPDIR/shopvivaliz-php-server.log" 2>&1 &
PHP_SERVER_PID=$!
echo "PHP Server PID: $PHP_SERVER_PID"
sleep 3

if ! kill -0 $PHP_SERVER_PID 2>/dev/null; then
  echo "ERROR: PHP server process died immediately"
  cat "$TMPDIR/shopvivaliz-php-server.log"
  exit 1
fi

for attempt in $(seq 1 30); do
  if curl -fsS "${BASE_URL}/index.php" -o /dev/null 2>/dev/null; then
    echo "✓ Storefront listener is responding"
    break
  fi
  if [ $attempt -eq 30 ]; then
    echo "ERROR: Storefront listener did not respond after 30 seconds"
    cat "$TMPDIR/shopvivaliz-php-server.log"
    exit 1
  fi
  sleep 1
done

assert_status() {
  local expected="$1" url="$2" method="${3:-GET}" payload="${4:-}" status
  if [[ "${method}" == "POST" ]]; then
    status=$(curl -sS -o "$TMPDIR/sv-response.json" -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "${payload}" "${url}")
  else
    status=$(curl -sS -o "$TMPDIR/sv-response.html" -w '%{http_code}' "${url}")
  fi
  if [[ "${status}" != "${expected}" ]]; then
    echo "Expected ${expected}, got ${status}: ${url}"
    cat "$TMPDIR/sv-response.json" 2>/dev/null || true
    cat "$TMPDIR/sv-response.html" 2>/dev/null || true
    exit 1
  fi
}

assert_status_one_of() {
  local allowed="$1" url="$2" status
  status=$(curl -sS -o "$TMPDIR/sv-response.json" -w '%{http_code}' "${url}")
  if [[ " ${allowed} " != *" ${status} "* ]]; then
    echo "Expected one of [${allowed}], got ${status}: ${url}"
    cat "$TMPDIR/sv-response.json" 2>/dev/null || true
    exit 1
  fi
}

assert_contains() { curl -fsS "$1" | grep -F "$2" >/dev/null; }
assert_contains_allow_error() { curl -sS "$1" | grep -F "$2" >/dev/null; }

assert_status 200 "${BASE_URL}/index.php"
assert_status 200 "${BASE_URL}/catalogo.php"
assert_status 200 "${BASE_URL}/carrinho.php"
assert_status 200 "${BASE_URL}/checkout.php"
assert_status 200 "${BASE_URL}/api/catalog/category-images.php"
assert_status 200 "${BASE_URL}/api/catalog/image-health.php"
assert_status 200 "${BASE_URL}/api/catalog/valid-image-products.php"
assert_status 422 "${BASE_URL}/api/catalog/image-by-product.php"
assert_status 200 "${BASE_URL}/api/catalog/stock-health.php"
assert_status 200 "${BASE_URL}/api/catalog/products-in-stock.php"
assert_status 422 "${BASE_URL}/api/catalog/stock-by-product.php"
assert_status 405 "${BASE_URL}/api/cart/validate.php"
assert_status 422 "${BASE_URL}/api/cart/validate.php" POST '{}'
assert_status_one_of "200 503" "${BASE_URL}/api/orders/health.php"
assert_status 200 "${BASE_URL}/api/orders/security-health.php"
assert_status 200 "${BASE_URL}/api/orders/idempotency-health.php"
assert_status 200 "${BASE_URL}/api/orders/context-health.php"
assert_status 200 "${BASE_URL}/api/site/official-reference.php"

assert_contains "${BASE_URL}/catalogo.php" "Produtos"
assert_contains "${BASE_URL}/carrinho.php" "Carrinho"
assert_contains "${BASE_URL}/checkout.php" "checkout-form"
assert_contains "${BASE_URL}/api/catalog/category-images.php" '"ok":true'
assert_contains "${BASE_URL}/api/catalog/image-health.php" '"image_health"'
assert_contains "${BASE_URL}/api/catalog/valid-image-products.php" '"products"'
assert_contains "${BASE_URL}/api/catalog/stock-health.php" '"stock_health"'
assert_contains "${BASE_URL}/api/catalog/products-in-stock.php" '"products"'
assert_contains_allow_error "${BASE_URL}/api/orders/health.php" '"endpoint":"orders"'
assert_contains "${BASE_URL}/api/orders/security-health.php" '"endpoint":"orders-security"'
assert_contains "${BASE_URL}/api/orders/idempotency-health.php" '"endpoint":"orders-idempotency"'
assert_contains "${BASE_URL}/api/orders/context-health.php" '"endpoint":"orders-context"'
assert_contains "${BASE_URL}/api/site/official-reference.php" '"endpoint":"official-site-reference"'
assert_contains "${BASE_URL}/api/site/official-reference.php" 'https://shopvivaliz.com.br'

assert_status 405 "${BASE_URL}/api/orders/create.php"
assert_status 405 "${BASE_URL}/api/orders/create-validated.php"
assert_status 405 "${BASE_URL}/api/melhorenvio/shipping-check-v2.php"
assert_status 422 "${BASE_URL}/api/orders/create.php" POST '{}'
assert_status 422 "${BASE_URL}/api/orders/create-validated.php" POST '{}'
assert_status 422 "${BASE_URL}/api/melhorenvio/shipping-check-v2.php" POST '{}'

# Este smoke valida apenas contratos locais/estruturais. Ele NUNCA pode ser
# usado como prova de que producao, frete, pagamentos ou integracoes funcionam.
echo "LOCAL_CONTRACT_SMOKE=PASS"
echo "Para declarar producao funcional, execute scripts/production-functional-audit.sh e exija PRODUCTION_FUNCTIONAL_AUDIT=PASS."
