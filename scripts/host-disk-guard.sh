#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

STATE_DIR="${HOME}/.local/state/shopvivaliz"
CONF_DIR="${HOME}/.config/shopvivaliz"
KEY_FILE="${CONF_DIR}/execution-provenance.key"
mkdir -p "$STATE_DIR" "$CONF_DIR"
chmod 700 "$CONF_DIR"
if [[ ! -s "$KEY_FILE" ]]; then
  openssl rand -hex 32 > "$KEY_FILE"
  chmod 600 "$KEY_FILE"
fi

exec 9>"${STATE_DIR}/disk-guard.lock"
flock -n 9 || exit 0

host="$(hostname)"
ts_start="$(date -u +%FT%TZ)"
if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
  execution_id="gh-${GITHUB_RUN_ID:-unknown}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-disk-guard}"
  origin_type="github-actions"
  origin_ref="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-unknown}/actions/runs/${GITHUB_RUN_ID:-unknown}"
  actor="${GITHUB_ACTOR:-unknown}"
  repo="${GITHUB_REPOSITORY:-unknown}"
  ref="${GITHUB_REF:-unknown}"
  sha="${GITHUB_SHA:-unknown}"
else
  execution_id="disk-guard-$(date -u +%Y%m%dT%H%M%SZ)-$$"
  origin_type="host"
  origin_ref="host-local"
  actor="${USER:-unknown}"
  repo="unknown"
  ref="unknown"
  sha="unknown"
fi

percent_used() {
  df -P / | awk 'NR==2 {gsub(/%/,"",$5); print $5}'
}

path_busy() {
  local root="$1" p cwd
  for p in /proc/[0-9]*/cwd; do
    cwd="$(readlink "$p" 2>/dev/null || :)"
    case "$cwd" in
      "$root"|"$root"/*) return 0 ;;
    esac
  done
  return 1
}

before="$(percent_used)"
removed_volumes=0
cache_actions=0

if (( before >= 80 )); then
  for cache in     "$HOME/.npm/_cacache"     "$HOME/.npm/_npx"     "$HOME/.cache/ms-playwright"     "$HOME/.cache/ms-playwright-arm64"     "$HOME/.cache/google-chrome-for-testing"; do
    if [[ -e "$cache" ]] && ! path_busy "$cache"; then
      rm -rf -- "$cache"
      cache_actions=$((cache_actions + 1))
    fi
  done

  for diag in "$HOME"/actions-runner-*/_diag; do
    [[ -d "$diag" ]] || continue
    find "$diag" -type f -mtime +14 -delete
  done

  if command -v docker >/dev/null 2>&1; then
    docker image prune -f >/dev/null
    docker builder prune -f --filter until=168h >/dev/null
  fi
fi

if (( before >= 80 )) && command -v docker >/dev/null 2>&1; then
  now_epoch="$(date +%s)"
  if (( before >= 85 )); then
    min_age=1800
  else
    min_age=21600
  fi
  while IFS= read -r volume; do
    [[ "$volume" =~ ^[0-9a-f]{64}$ ]] || continue
    [[ -z "$(docker ps -aq --filter volume="$volume")" ]] || continue
    created="$(docker volume inspect -f '{{.CreatedAt}}' "$volume" 2>/dev/null || :)"
    [[ -n "$created" ]] || continue
    created_epoch="$(date -d "$created" +%s 2>/dev/null || printf '%s' "$now_epoch")"
    age=$((now_epoch - created_epoch))
    (( age >= min_age )) || continue
    if docker volume rm "$volume" >/dev/null 2>&1; then
      removed_volumes=$((removed_volumes + 1))
    fi
  done < <(docker volume ls -qf dangling=true)
fi

after="$(percent_used)"
ts_end="$(date -u +%FT%TZ)"
payload="execution_id=$execution_id|origin_type=$origin_type|origin_ref=$origin_ref|actor=$actor|repo=$repo|ref=$ref|sha=$sha|host=$host|action=host_disk_guard|disk_before=${before}%|disk_after=${after}%|cache_actions=$cache_actions|removed_anonymous_volumes=$removed_volumes|timestamp_utc=$ts_end"
digest="$(printf '%s' "$payload" | sha256sum | awk '{print $1}')"
signature="$(printf '%s' "$payload" | openssl dgst -sha256 -hmac "$(cat "$KEY_FILE")" | awk '{print $2}')"
printf '{"execution_id":"%s","origin_type":"%s","origin_ref":"%s","actor":"%s","agent_tool":"deterministic-disk-guard","repository":"%s","ref":"%s","sha":"%s","host":"%s","action":"host_disk_guard","result":"COMPLETED","disk_before":"%s%%","disk_after":"%s%%","cache_actions":%s,"removed_anonymous_volumes":%s,"started_utc":"%s","timestamp_utc":"%s","payload_sha256":"%s","signature_status":"HMAC-SHA256","signing_key_id":"host-disk-guard-v1","signature":"%s"}\n' \
  "$execution_id" "$origin_type" "$origin_ref" "$actor" "$repo" "$ref" "$sha" "$host" "$before" "$after" "$cache_actions" "$removed_volumes" "$ts_start" "$ts_end" "$digest" "$signature" \
  >> "$STATE_DIR/execution-provenance.jsonl"

echo "disk_guard host=$host before=${before}% after=${after}% caches=$cache_actions anonymous_volumes_removed=$removed_volumes execution_id=$execution_id"
