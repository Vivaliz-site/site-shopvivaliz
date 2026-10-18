#!/usr/bin/env bash
# P4: Validate required PHP modules on Marina

set -Eeuo pipefail

echo "===== P4: PHP MODULES VALIDATION ====="
echo "Executed: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo ""

# Required modules for ShopVivaliz production
required_modules=(
    "soap"
    "sqlite3"
    "apcu"
    "redis"
    "gmp"
    "bz2"
    "curl"
    "json"
    "pdo"
    "pdo_mysql"
)

missing=()
present=()

for module in "${required_modules[@]}"; do
    if php -m 2>/dev/null | grep -q "^$module$"; then
        echo "✅ $module"
        present+=("$module")
    else
        echo "❌ $module — MISSING"
        missing+=("$module")
    fi
done

echo ""
echo "===== SUMMARY ====="
echo "Present: ${#present[@]} / ${#required_modules[@]}"
echo "Missing: ${#missing[@]}"

if [ ${#missing[@]} -gt 0 ]; then
    echo ""
    echo "❌ INSTALL REQUIRED:"
    echo "apt-get update && apt-get install -y php8.3-${missing[*]// /,php8.3-}"
    exit 1
else
    echo "✅ All required modules present"
    exit 0
fi
