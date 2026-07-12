#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8099}"
TMPDIR="${TMPDIR:-/tmp}"
PHP_SERVER_PID=""

cleanup() {
  if [[ -n "${PHP_SERVER_PID}" ]] && kill -0 "${PHP_SERVER_PID}" 2>/dev/null; then
    kill "${PHP_SERVER_PID}" || true
  fi
}
trap cleanup EXIT

# Start PHP server with retry logic
MAX_RETRIES=3
for attempt in $(seq 1 $MAX_RETRIES); do
  echo "Starting PHP server (attempt $attempt/$MAX_RETRIES)..."
  php -S 127.0.0.1:8099 -t . >"$TMPDIR/shopvivaliz-php-server.log" 2>&1 &
  PHP_SERVER_PID=$!
  sleep 2

  # Check if server is listening
  if lsof -i :8099 >/dev/null 2>&1 || netstat -tuln 2>/dev/null | grep -q 8099; then
    echo "✓ PHP server started (PID: $PHP_SERVER_PID)"
    break
  else
    if [ $attempt -lt $MAX_RETRIES ]; then
      echo "⚠️  Port 8099 not responding, retrying..."
      kill $PHP_SERVER_PID 2>/dev/null || true
      sleep 1
    fi
  fi
done

# Wait for server to be ready
for retry in $(seq 1 45); do
  if curl -fsS "${BASE_URL}/index.php" >/dev/null 2>&1; then
    echo "✓ Server ready after $retry attempts"
    break
  fi
  if [ $retry -eq 45 ]; then
    echo "❌ Server failed to start after 45 attempts"
    echo "Server logs:"
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

assert_contains() { curl -fsS "$1" | grep -Fq "$2"; }
assert_contains_allow_error() { curl -sS "$1" | grep -Fq "$2"; }

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
assert_status 503 "${BASE_URL}/api/orders/health.php"
assert_status 200 "${BASE_URL}/api/orders/security-health.php"
assert_status 200 "${BASE_URL}/api/orders/idempotency-health.php"
assert_status 200 "${BASE_URL}/api/orders/context-health.php"
assert_status 200 "${BASE_URL}/api/site/official-reference.php"

assert_contains "${BASE_URL}/catalogo.php" "Catálogo"
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
assert_contains "${BASE_URL}/api/site/official-reference.php" 'https://www.shopvivaliz.com.br'

assert_status 405 "${BASE_URL}/api/orders/create.php"
assert_status 405 "${BASE_URL}/api/orders/create-validated.php"
assert_status 405 "${BASE_URL}/api/melhorenvio/shipping-check-v2.php"
assert_status 422 "${BASE_URL}/api/orders/create.php" POST '{}'
assert_status 422 "${BASE_URL}/api/orders/create-validated.php" POST '{}'
assert_status 422 "${BASE_URL}/api/melhorenvio/shipping-check-v2.php" POST '{}'

echo "Storefront smoke tests passed."
