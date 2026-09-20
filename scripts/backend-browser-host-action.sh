#!/usr/bin/env bash
set -Eeuo pipefail

EXPECTED_HOST=always-free-arm-1787907847-26
APP=/opt/shopvivaliz-browser-host
STATE=/var/lib/shopvivaliz-browser
CONF=/etc/shopvivaliz-browser
MFA_SERVICE=shopvivaliz-browser-mfa.service
LOGIN_FILE="$STATE/tailscale-login.txt"

ACTION="${1:-}"
ORIGIN="${2:-github-control}"
TASK="${3:-browser-host}"
[[ "$(hostname)" == "$EXPECTED_HOST" ]] || { echo "wrong host" >&2; exit 20; }
[[ "$EUID" -eq 0 ]] || { echo "root required" >&2; exit 21; }
[[ "$ACTION" =~ ^(status|smoke|mfa-start|mfa-stop|cleanup|tailscale-login|tailscale-serve)$ ]] || {
  echo "unsupported action" >&2; exit 22;
}
[[ "$ORIGIN" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || { echo "invalid origin" >&2; exit 23; }
[[ "$TASK" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || { echo "invalid task" >&2; exit 23; }

tailscale_state() {
  if ! command -v tailscale >/dev/null 2>&1; then
    printf 'missing'
    return
  fi
  local state
  state="$(tailscale status --json 2>/dev/null | jq -r '.BackendState // "Unknown"' 2>/dev/null || printf 'Unknown')"
  printf '%s' "$state"
}

status_report() {
  local mfa_state ts_state
  mfa_state="$(systemctl is-active "$MFA_SERVICE" 2>/dev/null || printf 'inactive')"
  ts_state="$(tailscale_state)"
  echo "BROWSER_HOST_STATUS=ok"
  echo "MFA_SERVICE_STATE=$mfa_state"
  echo "TAILSCALE_STATE=$ts_state"
  if ss -ltnH '( sport = :6080 )' | grep -q '127.0.0.1:6080'; then
    echo "NOVNC_LOOPBACK=present"
  else
    echo "NOVNC_LOOPBACK=absent"
  fi
  if ss -ltnH '( sport = :9222 )' | grep -q '127.0.0.1:9222'; then
    echo "CDP_LOOPBACK=present"
  else
    echo "CDP_LOOPBACK=absent"
  fi
}

case "$ACTION" in
  status)
    status_report
    ;;
  smoke)
    test -x "$APP/node_modules/.bin/playwright"
    install -d -o shopbrowser -g shopbrowser -m 0750 "$STATE/artifacts/smoke"
    runuser -u shopbrowser -- env       HOME="$STATE"       PLAYWRIGHT_BROWSERS_PATH="$STATE/pw-browsers"       BROWSER_ARTIFACT_DIR="$STATE/artifacts/smoke"       node "$APP/browser-smoke.mjs"
    echo "BROWSER_SMOKE=PASS"
    ;;
  mfa-start)
    cat > "$CONF/mfa.env" <<EOF
BROWSER_PROFILE=mfa-default
BROWSER_SESSION_ORIGIN=$ORIGIN
BROWSER_SESSION_TASK=$TASK
BROWSER_SESSION_TTL_SECONDS=7200
EOF
    chown root:shopbrowser "$CONF/mfa.env"
    chmod 0640 "$CONF/mfa.env"
    systemctl restart "$MFA_SERVICE"
    for _ in $(seq 1 30); do
      if systemctl is-active --quiet "$MFA_SERVICE"          && timeout 1 bash -c '</dev/tcp/127.0.0.1/6080' 2>/dev/null; then
        break
      fi
      sleep 1
    done
    systemctl is-active --quiet "$MFA_SERVICE"
    timeout 2 bash -c '</dev/tcp/127.0.0.1/6080'
    echo "MFA_SESSION=READY"
    status_report
    ;;
  mfa-stop)
    systemctl stop "$MFA_SERVICE"
    if [[ "$(tailscale_state)" == "Running" ]]; then
      if ! tailscale serve --http=6080 off >/dev/null 2>&1; then
        echo "TAILSCALE_SERVE_DISABLE=not_configured"
      else
        echo "TAILSCALE_SERVE_DISABLE=PASS"
      fi
    fi
    echo "MFA_SESSION=STOPPED"
    status_report
    ;;
  cleanup)
    "$APP/browser-cleanup.sh"
    echo "BROWSER_CLEANUP=PASS"
    status_report
    ;;
  tailscale-login)
    systemctl enable --now tailscaled.service
    if [[ "$(tailscale_state)" == "Running" ]]; then
      rm -f "$LOGIN_FILE"
      echo "TAILSCALE_AUTH=READY"
      exit 0
    fi
    tmp="$(mktemp "$STATE/.tailscale-login.XXXXXX")"
    chmod 0600 "$tmp"
    set +e
    tailscale up       --hostname=shopvivaliz-browser-vm       --operator=ubuntu       --accept-dns=false       --accept-routes=false       --ssh=false       --timeout=8s >"$tmp" 2>&1
    rc=$?
    set -e
    if [[ "$(tailscale_state)" == "Running" ]]; then
      rm -f "$tmp" "$LOGIN_FILE"
      echo "TAILSCALE_AUTH=READY"
    elif grep -Eq 'https://login\.tailscale\.com/' "$tmp"; then
      mv -f "$tmp" "$LOGIN_FILE"
      chown ubuntu:ubuntu "$LOGIN_FILE"
      chmod 0600 "$LOGIN_FILE"
      echo "TAILSCALE_AUTH=INTERACTION_REQUIRED"
      echo "TAILSCALE_LOGIN_FILE=READY"
    else
      rm -f "$tmp"
      echo "tailscale login bootstrap failed rc=$rc" >&2
      exit 30
    fi
    ;;
  tailscale-serve)
    [[ "$(tailscale_state)" == "Running" ]] || { echo "tailscale authentication required" >&2; exit 31; }
    systemctl is-active --quiet "$MFA_SERVICE" || { echo "mfa service not active" >&2; exit 32; }
    tailscale serve --bg --yes --http=6080 http://127.0.0.1:6080 >/dev/null
    echo "TAILSCALE_SERVE=READY"
    echo "TAILSCALE_ACCESS_SCOPE=tailnet-only"
    ;;
esac
