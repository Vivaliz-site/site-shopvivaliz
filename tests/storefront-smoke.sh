#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8099}"
PHP_SERVER_PID=""

cleanup() {
  if [[ -n "${PHP_SERVER_PID}" ]] && kill -0 "${PHP_SERVER_PID}" 2>/dev/null; then
    kill "${PHP_SERVER_PID}" || true
  fi
}
trap cleanup EXIT

php -S 127.0.0.1:8099 -t . >/tmp/shopvivaliz-php-server.log 2>&1 &
PHP_SERVER_PID=$!

for _ in $(seq 1 30); do
  if curl -fsS "${BASE_URL}/index.php" >/dev/null; then
    break
  fi
  sleep 1
done

assert_status() {
  local expected="$1"
  local url="$2"
  local method="${3:-GET}"
  local payload="${4:-}"
  local status
  if [[ "${method}" == "POST" ]]; then
    status=$(curl -sS -o /tmp/sv-response.json -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "${payload}" "${url}")
  else
    status=$(curl -sS -o /tmp/sv-response.html -w '%{http_code}' "${url}")
  fi
  if [[ "${status}" != "${expected}" ]]; then
    echo "Expected ${expected}, got ${status}: ${url}"
    cat /tmp/sv-response.json 2>/dev/null || true
    cat /tmp/sv-response.html 2>/dev/null || true
    exit 1
  fi
}

assert_contains() {
  local url="$1"
  local needle="$2"
  curl -fsS "${url}" | grep -Fq "${needle}"
}

assert_status 200 "${BASE_URL}/index.php"
assert_status 200 "${BASE_URL}/catalogo.php"
assert_status 200 "${BASE_URL}/carrinho.php"
assert_status 200 "${BASE_URL}/checkout.php"
assert_status 200 "${BASE_URL}/api/catalog/category-images.php"
assert_status 200 "${BASE_URL}/api/catalog/image-health.php"

assert_contains "${BASE_URL}/catalogo.php" "Catálogo"
assert_contains "${BASE_URL}/carrinho.php" "Carrinho"
assert_contains "${BASE_URL}/checkout.php" "checkout-form"
assert_contains "${BASE_URL}/api/catalog/category-images.php" '"ok":true'
assert_contains "${BASE_URL}/api/catalog/image-health.php" '"image_health"'

assert_status 405 "${BASE_URL}/api/orders/create-validated.php"
assert_status 405 "${BASE_URL}/api/melhorenvio/shipping-check-v2.php"
assert_status 422 "${BASE_URL}/api/orders/create-validated.php" POST '{}'
assert_status 422 "${BASE_URL}/api/melhorenvio/shipping-check-v2.php" POST '{}'

echo "Storefront smoke tests passed."
