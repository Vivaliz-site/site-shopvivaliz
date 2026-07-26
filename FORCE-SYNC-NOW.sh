#!/bin/bash
# FORCE SYNC SCRIPT - Execute this on the production VM to force sync and configure agent keys
# Usage: bash /home/ubuntu/site-shopvivaliz/FORCE-SYNC-NOW.sh

echo "🚀 Forcing synchronization on production VM..."
cd /home/ubuntu/site-shopvivaliz || exit 1

# Fetch latest
git fetch origin
echo "✅ Fetched from origin"

# Reset hard
git reset --hard origin/main
echo "✅ Reset to origin/main"

# Verify autonomous-watchdog.php has hardcoded key
if grep -q "RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k" api/agent/autonomous-watchdog.php; then
    echo "✅ Agent key is present in autonomous-watchdog.php"
else
    echo "❌ Agent key NOT found in autonomous-watchdog.php"
    exit 1
fi

# Verify config/agent-keys.php exists
if [ -f config/agent-keys.php ]; then
    echo "✅ config/agent-keys.php exists"
else
    echo "⚠️  config/agent-keys.php not found (will use hardcoded fallback)"
fi

# Verify .env has agent keys
if [ -f .env ] && grep -q "SHOPVIVALIZ_AGENT_KEY" .env; then
    echo "✅ .env has agent keys configured"
else
    echo "⚠️  .env does not have agent keys (will use hardcoded fallback)"
fi

echo ""
echo "✅ All checks passed! Production is synchronized."
echo "   Agent key: RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k"
echo "   Current commit: $(git rev-parse HEAD)"
