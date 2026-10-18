#!/usr/bin/env bash
# P2: Validate Mercado Livre OAuth renewal

set -Eeuo pipefail

echo "===== P2: MERCADO LIVRE OAUTH RENEWAL CHECK ====="
echo "Executed: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo ""

# Check if renewal timer/service is active
echo "1. Checking ML token renewer status..."
systemctl is-active shopvivaliz-mercadolivre-token-renewer.service 2>/dev/null && echo "✅ Service active" || echo "❌ Service inactive"

# Check PostgreSQL for last renewal timestamp
echo ""
echo "2. Checking last renewal in PostgreSQL..."
psql -U postgres -d mlrr -c "SELECT MAX(updated_at) FROM ml_oauth_tokens WHERE provider='mercadolivre'" 2>/dev/null || echo "⚠️  PostgreSQL not accessible"

# Check .env for ML_TOKEN_OWNER
echo ""
echo "3. Checking ML ownership in .env..."
grep -q "ML_TOKEN_OWNER=mlrr" /home/ubuntu/shopvivaliz-deploy/shared/.env 2>/dev/null && echo "✅ Ownership set to MLRR" || echo "⚠️  Check ML_TOKEN_OWNER in .env"

# Check daemon-mercadolivre-token-renewer.php exists
echo ""
echo "4. Checking renewal daemon in release..."
if [ -f "/home/ubuntu/shopvivaliz-deploy/current/daemon-mercadolivre-token-renewer.php" ]; then
    echo "✅ Renewal daemon present"
else
    echo "❌ Renewal daemon missing from release"
fi

# Check for recent errors in logs
echo ""
echo "5. Checking recent errors in ML renewal logs..."
journalctl -u shopvivaliz-mercadolivre-token-renewer.service -n 10 --no-pager 2>/dev/null | grep -i "error\|fail" || echo "✅ No recent errors"

echo ""
echo "===== VALIDATION COMPLETE ====="
echo ""
echo "Status: ✅ OK if all checks pass"
echo "Status: ❌ BLOCKER if ownership=mlrr but service is inactive"
echo "Status: ⚠️  WARNING if PostgreSQL not accessible (check network)"
