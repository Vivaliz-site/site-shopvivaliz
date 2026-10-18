#!/usr/bin/env bash
# Diagnose migration database status — origem vs. Marina
# Sem fazer nenhuma mutação

set -Eeuo pipefail

ORIGIN_HOST="shopvivaliz-free-a1"
ORIGIN_IP="137.131.149.55"
CONTROLADORA_IP="10.0.1.38"

echo "===== DATABASE MIGRATION STATUS DIAGNOSIS ====="
echo "Executed: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo ""

# Function to safely query MySQL without credentials in output
query_mysql_count() {
    local db="$1"
    local table="$2"
    mysql -h 127.0.0.1 -u root -Nse "SELECT COUNT(*) FROM $db.$table" 2>/dev/null || echo "ERROR"
}

# MySQL tables to check
echo "=== MYSQL SHOPVIVALIZ (origen) ==="
echo -n "shopvivaliz.orders: "
query_mysql_count shopvivaliz orders
echo -n "shopvivaliz.products: "
query_mysql_count shopvivaliz products
echo -n "shopvivaliz.users: "
query_mysql_count shopvivaliz users

echo ""
echo "=== MYSQL AMAZON_RETURNS_SAFET ==="
echo -n "amazon_returns_safet.amazon_return_cases: "
query_mysql_count amazon_returns_safet amazon_return_cases 2>/dev/null || echo "TABLE_NOT_FOUND"
echo -n "amazon_returns_safet.tenant_registry: "
query_mysql_count amazon_returns_safet tenant_registry 2>/dev/null || echo "TABLE_NOT_FOUND"

echo ""
echo "=== MYSQL ML_PRICING_PROD ==="
echo -n "ml_pricing_prod tables (count): "
mysql -h 127.0.0 1 -u root -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ml_pricing_prod'" 2>/dev/null || echo "DB_NOT_FOUND"

echo ""
echo "=== POSTGRESQL MLRR ==="
echo "Status: Connection requires Supabase credentials (deferred)"
echo ""

echo "✅ Diagnosis complete. No mutations performed."
