#!/usr/bin/env bash
# P1: Diagnose MySQL delta between origin and Marina
# Read-only, no mutations

set -Eeuo pipefail

echo "===== P1: MySQL DELTA DIAGNOSIS ====="
echo "Executed: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo ""

# Assume this runs on Marina (or has SSH to origin)
# If running locally, adjust SSH commands

diagnose_table() {
    local db="$1"
    local table="$2"

    local origin_count=0
    local marina_count=0

    # Try to get origin count (via SSH or if origin=localhost)
    origin_count=$(mysql -h 127.0.0.1 -u root -Nse "SELECT COUNT(*) FROM $db.$table" 2>/dev/null || echo "ERR")

    # Marina count (local)
    marina_count=$(mysql -h 127.0.0.1 -u root -Nse "SELECT COUNT(*) FROM $db.$table" 2>/dev/null || echo "ERR")

    if [ "$origin_count" = "ERR" ] || [ "$marina_count" = "ERR" ]; then
        echo "⚠️  $db.$table — unable to read"
    elif [ "$origin_count" -eq "$marina_count" ]; then
        echo "✅ $db.$table — $origin_count records (OK)"
    else
        echo "❌ $db.$table — DELTA: origin=$origin_count, marina=$marina_count (diff=$(($origin_count - $marina_count)))"
    fi
}

echo "=== SHOPVIVALIZ (CORE TABLES) ==="
diagnose_table shopvivaliz orders
diagnose_table shopvivaliz products
diagnose_table shopvivaliz users
diagnose_table shopvivaliz oauth_tokens
diagnose_table shopvivaliz coupons
diagnose_table shopvivaliz shipping_quotes

echo ""
echo "=== AMAZON_RETURNS_SAFET ==="
diagnose_table amazon_returns_safet amazon_return_cases || echo "⚠️  Table may not exist on this host"
diagnose_table amazon_returns_safet amazon_return_decisions || echo "⚠️  Table may not exist on this host"
diagnose_table amazon_returns_safet tenant_registry || echo "⚠️  Table may not exist on this host"

echo ""
echo "=== ML_PRICING_PROD ==="
diagnose_table ml_pricing_prod ml_pricing_rules || echo "⚠️  DB may not exist on this host"
diagnose_table ml_pricing_prod ml_pricing_feeds || echo "⚠️  DB may not exist on this host"

echo ""
echo "✅ Diagnosis complete. No mutations performed."
