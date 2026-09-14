#!/usr/bin/env bash
set -Eeuo pipefail

: "${CLOUDFLARE_API_TOKEN:?missing CLOUDFLARE_API_TOKEN}"
: "${CLOUDFLARE_DNS_EDIT_TOKEN:?missing CLOUDFLARE_DNS_EDIT_TOKEN}"

ZONE_NAME="${ZONE_NAME:-shopvivaliz.com.br}"
PUBLIC_HOSTNAME="${PUBLIC_HOSTNAME:-returns.shopvivaliz.com.br}"
TUNNEL_NAME="${TUNNEL_NAME:-amazon-returns-safet-a1}"
LOCAL_PORT="${LOCAL_PORT:-18080}"
API='https://api.cloudflare.com/client/v4'
TOKEN_ROOT="${TOKEN_ROOT:-/home/ubuntu/.local/share/amazon-returns-cloudflared}"
CONTAINER_NAME="${CONTAINER_NAME:-amazon-returns-cloudflared}"
IMAGE="${CLOUDFLARED_IMAGE:-cloudflare/cloudflared:latest}"

cf_api() {
  local token="$1" method="$2" path="$3" body="${4:-}"
  local args=(-fsS -X "$method" -H "Authorization: Bearer $token" -H 'Content-Type: application/json')
  [[ -n "$body" ]] && args+=(--data "$body")
  curl "${args[@]}" "$API$path"
}

require_success() {
  local label="$1" payload="$2"
  jq -e '.success == true' >/dev/null <<<"$payload" || {
    echo "FALHOU: Cloudflare $label" >&2
    jq -c '{errors,messages}' <<<"$payload" >&2
    exit 1
  }
}

[[ "$LOCAL_PORT" =~ ^[0-9]+$ ]] || { echo 'LOCAL_PORT invalida' >&2; exit 64; }

sudo install -d -m 0755 /etc/apache2/conf-available /etc/apache2/sites-available
cat <<EOF | sudo tee /etc/apache2/conf-available/amazon-returns-tunnel-listener.conf >/dev/null
Listen 127.0.0.1:${LOCAL_PORT}
EOF
cat <<EOF | sudo tee /etc/apache2/sites-available/amazon-returns-tunnel.conf >/dev/null
<VirtualHost 127.0.0.1:${LOCAL_PORT}>
    ServerName ${PUBLIC_HOSTNAME}
    DocumentRoot /home/ubuntu/amazon-returns-deploy/current
    <Directory /home/ubuntu/amazon-returns-deploy/current>
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>
    <LocationMatch "^/api/amazon-returns/(bridge|status-bridge)\\.php$">
        LimitRequestBody 131072
    </LocationMatch>
    ErrorLog \${APACHE_LOG_DIR}/amazon-returns-tunnel-error.log
    CustomLog \${APACHE_LOG_DIR}/amazon-returns-tunnel-access.log combined
</VirtualHost>
EOF
sudo a2enconf amazon-returns-tunnel-listener >/dev/null
sudo a2ensite amazon-returns-tunnel >/dev/null
sudo apache2ctl configtest
sudo systemctl reload apache2
curl -fsS --max-time 8 -H "Host: $PUBLIC_HOSTNAME" "http://127.0.0.1:${LOCAL_PORT}/" >/dev/null

zone_json="$(cf_api "$CLOUDFLARE_DNS_EDIT_TOKEN" GET "/zones?name=$ZONE_NAME&status=active&per_page=1")"
require_success 'zone lookup' "$zone_json"
zone_id="$(jq -r '.result[0].id // empty' <<<"$zone_json")"
account_id="$(jq -r '.result[0].account.id // empty' <<<"$zone_json")"
[[ -n "$zone_id" && -n "$account_id" ]] || { echo 'FALHOU: zona/conta ausente' >&2; exit 1; }

tunnels_json="$(cf_api "$CLOUDFLARE_API_TOKEN" GET "/accounts/$account_id/cfd_tunnel?is_deleted=false&name=$TUNNEL_NAME&per_page=100")"
require_success 'tunnel lookup' "$tunnels_json"
tunnel_id="$(jq -r --arg n "$TUNNEL_NAME" '.result[]? | select(.name == $n) | .id' <<<"$tunnels_json" | head -1)"
if [[ -z "$tunnel_id" ]]; then
  tunnel_body="$(jq -nc --arg name "$TUNNEL_NAME" '{name:$name,config_src:"cloudflare"}')"
  tunnel_json="$(cf_api "$CLOUDFLARE_API_TOKEN" POST "/accounts/$account_id/cfd_tunnel" "$tunnel_body")"
  require_success 'tunnel create' "$tunnel_json"
  tunnel_id="$(jq -r '.result.id // empty' <<<"$tunnel_json")"
fi
[[ -n "$tunnel_id" ]] || { echo 'FALHOU: tunnel id ausente' >&2; exit 1; }

config_body="$(jq -nc --arg host "$PUBLIC_HOSTNAME" --arg service "http://127.0.0.1:${LOCAL_PORT}" '{config:{ingress:[{hostname:$host,service:$service,originRequest:{httpHostHeader:$host}},{service:"http_status:404"}]}}')"
config_json="$(cf_api "$CLOUDFLARE_API_TOKEN" PUT "/accounts/$account_id/cfd_tunnel/$tunnel_id/configurations" "$config_body")"
require_success 'tunnel configuration' "$config_json"

records_json="$(cf_api "$CLOUDFLARE_DNS_EDIT_TOKEN" GET "/zones/$zone_id/dns_records?name=$PUBLIC_HOSTNAME&per_page=10")"
require_success 'dns lookup' "$records_json"
record_id="$(jq -r '.result[0].id // empty' <<<"$records_json")"
dns_body="$(jq -nc --arg name "$PUBLIC_HOSTNAME" --arg target "$tunnel_id.cfargotunnel.com" '{type:"CNAME",name:$name,content:$target,proxied:true,ttl:1}')"
if [[ -n "$record_id" ]]; then
  dns_json="$(cf_api "$CLOUDFLARE_DNS_EDIT_TOKEN" PUT "/zones/$zone_id/dns_records/$record_id" "$dns_body")"
else
  dns_json="$(cf_api "$CLOUDFLARE_DNS_EDIT_TOKEN" POST "/zones/$zone_id/dns_records" "$dns_body")"
fi
require_success 'dns upsert' "$dns_json"

token_json="$(cf_api "$CLOUDFLARE_API_TOKEN" GET "/accounts/$account_id/cfd_tunnel/$tunnel_id/token")"
require_success 'tunnel token' "$token_json"
tunnel_token="$(jq -r '.result // empty' <<<"$token_json")"
[[ -n "$tunnel_token" ]] || { echo 'FALHOU: tunnel token ausente' >&2; exit 1; }
install -d -m 0700 "$TOKEN_ROOT"
printf '%s' "$tunnel_token" > "$TOKEN_ROOT/token"
chmod 0600 "$TOKEN_ROOT/token"

docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
docker run -d --name "$CONTAINER_NAME" --restart unless-stopped --network host \
  -v "$TOKEN_ROOT/token:/run/secrets/cloudflared-token:ro" \
  "$IMAGE" tunnel --no-autoupdate run --token-file /run/secrets/cloudflared-token >/dev/null

for _ in $(seq 1 30); do
  docker logs "$CONTAINER_NAME" 2>&1 | grep -q 'Registered tunnel connection' && break
  sleep 2
done
docker logs "$CONTAINER_NAME" 2>&1 | grep -q 'Registered tunnel connection'
for _ in $(seq 1 30); do
  curl -fsS --max-time 10 "https://${PUBLIC_HOSTNAME}/" >/dev/null && break
  sleep 2
done
curl -fsS --max-time 10 "https://${PUBLIC_HOSTNAME}/" >/dev/null

echo "RETURNS_TUNNEL_OK hostname=$PUBLIC_HOSTNAME tunnel_id=$tunnel_id local_port=$LOCAL_PORT"
