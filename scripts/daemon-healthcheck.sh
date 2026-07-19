#!/bin/bash
###########################################################################
# ShopVivaliz Daemon Health Check
# Monitora saúde dos daemons e reinicia se necessário
# Roda a cada 5 minutos via cron
###########################################################################

LOGDIR="/home/ubuntu/site-shopvivaliz/logs"
HEALTHLOG="$LOGDIR/daemon-health.log"
mkdir -p "$LOGDIR"

timestamp() {
    date '+[%Y-%m-%d %H:%M:%S]'
}

log() {
    echo "$(timestamp) $1" | tee -a "$HEALTHLOG"
}

check_daemon() {
    local daemon_name=$1
    local service_name="shopvivaliz-${daemon_name}"
    local status=$(systemctl is-active "$service_name" 2>/dev/null)

    if [ "$status" != "active" ]; then
        log "ALERT: $service_name is $status"
        systemctl start "$service_name" 2>&1 | tee -a "$HEALTHLOG"
        log "RESTART: Attempted to restart $service_name"
        return 1
    else
        log "OK: $service_name is active"
        return 0
    fi
}

check_token_renewal() {
    # Check if token was renewed in last 3 hours
    local token_file="/home/ubuntu/site-shopvivaliz/.env"
    local now=$(date +%s)
    local mtime=$(stat -c %Y "$token_file" 2>/dev/null || echo 0)
    local age=$((now - mtime))
    local three_hours=10800

    if [ $age -gt $three_hours ]; then
        log "WARNING: .env not updated in $age seconds (threshold: $three_hours)"
        # Force a renewal by restarting daemon
        systemctl restart shopvivaliz-token-renewer
        log "RESTART: Forced token-renewer restart due to stale .env"
    else
        log "OK: .env updated $age seconds ago"
    fi
}

check_cache_freshness() {
    # Check if products cache is fresh
    local cache_file="/home/ubuntu/site-shopvivaliz/storage/products-cache-ativos.json"
    local now=$(date +%s)
    local mtime=$(stat -c %Y "$cache_file" 2>/dev/null || echo 0)
    local age=$((now - mtime))
    local five_minutes=300

    if [ ! -f "$cache_file" ]; then
        log "ERROR: Cache file not found: $cache_file"
        return 1
    fi

    local product_count=$(jq '.total // 0' "$cache_file" 2>/dev/null || echo 0)

    if [ $age -gt $((five_minutes * 2)) ]; then
        log "WARNING: Cache not updated in $age seconds (expected < $((five_minutes * 2)))"
        return 1
    else
        log "OK: Cache fresh ($age sec ago, $product_count products)"
        return 0
    fi
}

check_api_endpoint() {
    # Test API endpoint responds
    local response=$(curl -s -w "\n%{http_code}" \
        -H "Accept: application/json" \
        "https://shopvivaliz.com.br/api/catalog/products.php?limit=1" 2>/dev/null)

    local http_code=$(echo "$response" | tail -n 1)
    local body=$(echo "$response" | head -n -1)

    if [ "$http_code" = "200" ]; then
        local count=$(echo "$body" | jq '.count // 0' 2>/dev/null || echo 0)
        log "OK: API endpoint returns HTTP 200 with $count products"
        return 0
    else
        log "ERROR: API endpoint returned HTTP $http_code"
        return 1
    fi
}

###########################################################################
# MAIN CHECKS
###########################################################################

log "========== HEALTH CHECK START =========="

check_daemon "token-renewer"
token_status=$?

check_daemon "sync-products"
sync_status=$?

check_token_renewal
token_renewal_status=$?

check_cache_freshness
cache_status=$?

check_api_endpoint
api_status=$?

log "========== HEALTH CHECK END =========="

# Exit with error if any critical check failed
if [ $token_status -ne 0 ] || [ $sync_status -ne 0 ]; then
    exit 1
fi

exit 0
