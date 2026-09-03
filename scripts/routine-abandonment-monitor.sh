#!/usr/bin/env bash
# ShopVivaliz - Routine Abandonment Monitor (Linux hosts)
# Deterministico, sem chamadas de IA (ver CLAUDE.md: "Nunca usar IA paga em cron/timer,
# daemon, watcher, autorepair, polling ou loop recorrente").
#
# Sinaliza timers/servicos systemd "shopvivaliz-*" fora da allowlist e jobs `at`
# pendentes com nome suspeito (oauth/click/verify/probe). Nao apaga, nao para nada.
set -Eeuo pipefail

REPO="/home/ubuntu/site-shopvivaliz"
LOG_DIR="$REPO/logs"
STATUS_DIR="$REPO/.agent-status"
LOG_FILE="$LOG_DIR/routine-abandonment-monitor.log"
STATUS_FILE="$STATUS_DIR/routine-abandonment-monitor.json"

mkdir -p "$LOG_DIR" "$STATUS_DIR"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"; }

# Unidades 24h/watchdog conhecidas e documentadas -- nunca sinalizar.
# Ver docs/DESKTOP-COMMANDER-24H.md.
ALLOWLIST=(
  "shopvivaliz-desktop-commander.service"
  "shopvivaliz-desktop-commander-guardian.service"
  "shopvivaliz-desktop-commander-guardian.timer"
  "shopvivaliz-amazon-returns.service"
  "shopvivaliz-queue-worker.service"
  "shopvivaliz-shopee-token-renewer.service"
  "shopvivaliz-token-renewer.service"
)

is_allowlisted() {
  local name="$1"
  for a in "${ALLOWLIST[@]}"; do
    [[ "$name" == "$a" ]] && return 0
  done
  return 1
}

flagged_json="[]"
flagged_count=0
reviewed_count=0
tmp_flagged=$(mktemp)
echo "[" > "$tmp_flagged"
first=1

while IFS= read -r unit; do
  unit=$(echo "$unit" | awk '{print $1}')
  [[ -z "$unit" ]] && continue
  reviewed_count=$((reviewed_count + 1))
  if is_allowlisted "$unit"; then continue; fi

  reason=""
  # Servico/timer nao documentado com nome shopvivaliz-*.
  reason="undocumented_unit_not_in_allowlist"

  if [[ $first -eq 0 ]]; then echo "," >> "$tmp_flagged"; fi
  first=0
  cat >> "$tmp_flagged" <<EOF
  {"unit": "$unit", "reason": "$reason"}
EOF
  flagged_count=$((flagged_count + 1))
  log "ABANDONED_CANDIDATE unit=$unit reason=$reason"
done < <(systemctl list-units --all --type=service,timer --no-legend 2>/dev/null | awk '{print $1}' | grep -i '^shopvivaliz-' || true)

# Jobs `at` pendentes com nome suspeito (oauth/click/verify/probe one-shot esquecido).
if command -v atq >/dev/null 2>&1; then
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    job_id=$(echo "$line" | awk '{print $1}')
    job_cmd=$(at -c "$job_id" 2>/dev/null | grep -iE 'oauth|click|verify|probe' | head -1)
    if [[ -n "$job_cmd" ]]; then
      reviewed_count=$((reviewed_count + 1))
      if [[ $first -eq 0 ]]; then echo "," >> "$tmp_flagged"; fi
      first=0
      cat >> "$tmp_flagged" <<EOF
  {"unit": "at-job-$job_id", "reason": "pending_at_job_matches_auth_bypass_pattern"}
EOF
      flagged_count=$((flagged_count + 1))
      log "ABANDONED_CANDIDATE unit=at-job-$job_id reason=pending_at_job_matches_auth_bypass_pattern"
    fi
  done < <(atq 2>/dev/null || true)
fi

echo "]" >> "$tmp_flagged"

if [[ $flagged_count -eq 0 ]]; then
  log "OK reviewed=$reviewed_count allowlisted=${#ALLOWLIST[@]} flagged=0"
  status_val="ok"
  ok_val="true"
else
  status_val="attention"
  ok_val="false"
fi

flagged_json=$(cat "$tmp_flagged")
rm -f "$tmp_flagged"

cat > "$STATUS_FILE" <<EOF
{
  "generated_at": "$(date -u '+%Y-%m-%dT%H:%M:%SZ')",
  "host": "$(hostname)",
  "ok": $ok_val,
  "status": "$status_val",
  "reviewed_count": $reviewed_count,
  "allowlist_count": ${#ALLOWLIST[@]},
  "flagged": $flagged_json
}
EOF

if [[ $flagged_count -gt 0 ]]; then
  echo "ATTENTION: $flagged_count candidate(s) de rotina abandonada. Ver $STATUS_FILE"
  exit 1
else
  echo "OK: nenhuma rotina abandonada detectada."
  exit 0
fi
