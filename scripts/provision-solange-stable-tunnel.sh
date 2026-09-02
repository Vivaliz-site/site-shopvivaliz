#!/usr/bin/env bash
set -Eeuo pipefail

: "${CLOUDFLARE_API_TOKEN:?missing CLOUDFLARE_API_TOKEN}"
: "${CLOUDFLARE_DNS_EDIT_TOKEN:?missing CLOUDFLARE_DNS_EDIT_TOKEN}"
: "${SSH_KEY_FILE:?missing SSH_KEY_FILE}"
: "${SSH_KNOWN_HOSTS_FILE:?missing SSH_KNOWN_HOSTS_FILE}"

ZONE_NAME="${ZONE_NAME:-shopvivaliz.com.br}"
PUBLIC_HOSTNAME="${PUBLIC_HOSTNAME:-solange-staging.shopvivaliz.com.br}"
TUNNEL_NAME="${TUNNEL_NAME:-solange-staging-shopvivaliz}"
REMOTE_HOST="${REMOTE_HOST:-144.22.157.209}"
REMOTE_USER="${REMOTE_USER:-ubuntu}"
REMOTE_ROOT="${REMOTE_ROOT:-/home/ubuntu/solange-client-demo}"
API='https://api.cloudflare.com/client/v4'

cf_api() {
  local token="$1" method="$2" path="$3" body="${4:-}"
  local args=(-fsS -X "$method" -H "Authorization: Bearer $token" -H 'Content-Type: application/json')
  if [[ -n "$body" ]]; then args+=(--data "$body"); fi
  curl "${args[@]}" "$API$path"
}

require_success() {
  local label="$1" payload="$2"
  jq -e '.success == true' >/dev/null <<<"$payload" || {
    echo "FALHOU: Cloudflare ${label}" >&2
    jq -c '{errors,messages}' <<<"$payload" >&2
    exit 1
  }
}
zone_json="$(cf_api "$CLOUDFLARE_DNS_EDIT_TOKEN" GET "/zones?name=$ZONE_NAME&status=active&per_page=1")"
require_success 'zone lookup' "$zone_json"
zone_id="$(jq -r '.result[0].id // empty' <<<"$zone_json")"
account_id="$(jq -r '.result[0].account.id // empty' <<<"$zone_json")"
[[ -n "$zone_id" && -n "$account_id" ]] || {
  echo 'FALHOU: zona/conta Cloudflare nao encontrada' >&2
  exit 1
}

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
config_body="$(jq -nc --arg host "$PUBLIC_HOSTNAME" '{config:{ingress:[{hostname:$host,service:"http://127.0.0.1:3300",originRequest:{}},{service:"http_status:404"}]}}')"
config_json="$(cf_api "$CLOUDFLARE_API_TOKEN" PUT "/accounts/$account_id/cfd_tunnel/$tunnel_id/configurations" "$config_body")"
require_success 'tunnel configuration' "$config_json"

records_json="$(cf_api "$CLOUDFLARE_DNS_EDIT_TOKEN" GET "/zones/$zone_id/dns_records?type=CNAME&name=$PUBLIC_HOSTNAME&per_page=10")"
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
ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$SSH_KNOWN_HOSTS_FILE" -o ConnectTimeout=10 -i "$SSH_KEY_FILE")
token_tmp="$(mktemp)"
trap 'rm -f "$token_tmp"' EXIT
printf '%s' "$tunnel_token" > "$token_tmp"
chmod 600 "$token_tmp"
scp -q "${ssh_opts[@]}" "$token_tmp" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_ROOT/cloudflared.token.next"

remote_script="$(cat <<'REMOTE'
set -Eeuo pipefail
root="${REMOTE_ROOT:?missing REMOTE_ROOT}"
cloudflared_image="cloudflare/cloudflared@sha256:0aa26e284f05e6c77ae375b8c9c11d9eb6a448fb7bcd8d40f31cb6176189eb38"
container_exists() {
  docker inspect "$1" >/dev/null 2>&1
}
remove_container_if_present() {
  local name="$1"
  if container_exists "$name"; then
    docker rm -f "$name" >/dev/null
  fi
}
mv "$root/cloudflared.token.next" "$root/cloudflared.token"
chmod 600 "$root/cloudflared.token"
remove_container_if_present solange-demo-tunnel-stable
docker run -d --name solange-demo-tunnel-stable --restart unless-stopped --network host \
  -v "$root/cloudflared.token:/run/secrets/cloudflared-token:ro" \
  "$cloudflared_image" tunnel --no-autoupdate run --token-file /run/secrets/cloudflared-token >/dev/null
connected=false
for _ in $(seq 1 30); do
  if docker logs solange-demo-tunnel-stable 2>&1 | grep -q 'Registered tunnel connection'; then
    connected=true
    break
  fi
  sleep 2
done
[[ "$connected" == true ]] || {
  docker logs --tail 80 solange-demo-tunnel-stable >&2
  exit 1
}
stable_url="https://$PUBLIC_HOSTNAME"
sed -i -E "s#^NEXT_PUBLIC_SUPABASE_URL=.*#NEXT_PUBLIC_SUPABASE_URL=$stable_url#; s#^APP_URL=.*#APP_URL=$stable_url#" "$root/app/.env.production.local"
docker run --rm --network host -v "$root/app:/app" -w /app node:24-bookworm-slim npm run build
docker restart solange-client-demo-web >/dev/null
printf '%s\n' "$stable_url" > "$root/state/current-url.txt"
REMOTE
)"
printf -v remote_host_env '%q' "$PUBLIC_HOSTNAME"
printf -v remote_root_env '%q' "$REMOTE_ROOT"
ssh "${ssh_opts[@]}" "$REMOTE_USER@$REMOTE_HOST" "PUBLIC_HOSTNAME=$remote_host_env REMOTE_ROOT=$remote_root_env bash -s" <<<"$remote_script"

stable_url="https://$PUBLIC_HOSTNAME"
for _ in $(seq 1 30); do
  if curl --fail --silent --show-error --max-time 10 "$stable_url/api/health" >/dev/null; then
    break
  fi
  sleep 2
done
curl --fail --silent --show-error --max-time 10 "$stable_url/api/health" >/dev/null
curl --fail --silent --show-error --max-time 10 "$stable_url/" >/dev/null
finalize_script="$(cat <<'REMOTE'
set -Eeuo pipefail
for name in solange-demo-reconciler solange-demo-tunnel solange-demo-caddy; do
  if docker inspect "$name" >/dev/null 2>&1; then
    if [[ "$(docker inspect -f '{{.State.Running}}' "$name")" == true ]]; then
      docker stop "$name" >/dev/null
    fi
  fi
done
REMOTE
)"
ssh "${ssh_opts[@]}" "$REMOTE_USER@$REMOTE_HOST" 'bash -s' <<<"$finalize_script"

echo "COMPROVADO: tunnel Cloudflare persistente ativo em $stable_url"
echo "TUNNEL_ID=$tunnel_id"
