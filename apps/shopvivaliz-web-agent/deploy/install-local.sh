#!/usr/bin/env bash
set -euo pipefail
# Intentional dry-run by default; refuses to publish any ingress or secrets.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ "${1:-}" != "--install-local" ]]; then
  echo 'Dry-run only: requires --install-local after explicit host review.'
  echo 'Will install isolated service bound to 127.0.0.1; no Cloudflare/DNS changes.'
  exit 0
fi
if [[ "$(id -u)" -ne 0 ]]; then echo 'Run as authorized maintenance user with sudo' >&2; exit 1; fi
id shopvivaliz-web-agent >/dev/null 2>&1 || useradd --system --home-dir /var/lib/shopvivaliz-web-agent --shell /usr/sbin/nologin shopvivaliz-web-agent
install -d -o shopvivaliz-web-agent -g shopvivaliz-web-agent -m 0700 /var/lib/shopvivaliz-web-agent
install -d -o root -g root -m 0755 /opt/shopvivaliz-web-agent /etc/shopvivaliz-web-agent
if [[ -f /etc/shopvivaliz-web-agent/service.env ]]; then echo 'Secrets already provisioned; not replacing'; else
  umask 077
  local_token="$(openssl rand -hex 32)"
  printf 'WEB_AGENT_MCP_TOKEN=%s\nWEB_AGENT_PROFILE_DIR=/var/lib/shopvivaliz-web-agent/browser-profile\nWEB_AGENT_CHROME_BINARY=/opt/shopvivaliz-browser/chrome-linux/chrome\nWEB_AGENT_PORT=5598\n' "$local_token" >/etc/shopvivaliz-web-agent/service.env
  chmod 0600 /etc/shopvivaliz-web-agent/service.env
  unset local_token
fi
if ! test -x /opt/shopvivaliz-browser/chrome-linux/chrome; then echo 'Approved isolated Chromium binary missing; aborting' >&2; exit 3; fi
previous_release=""
if [ -L /opt/shopvivaliz-web-agent/current ]; then
  previous_release="$(readlink -f /opt/shopvivaliz-web-agent/current)"
  test -d "$previous_release" || { echo 'Previous release path invalid' >&2; exit 4; }
fi
release="/opt/shopvivaliz-web-agent/releases/$(date -u +%Y%m%dT%H%M%SZ)-$$"
install -d -m 0755 "$release"
cp -a "$ROOT/src" "$ROOT/web" "$ROOT/package.json" "$ROOT/package-lock.json" "$ROOT/README.md" "$release/"
cd "$release"
npm ci --omit=dev --ignore-scripts --no-audit --no-fund
chown -R root:root "$release"
install -m 0644 "$ROOT/deploy/shopvivaliz-web-agent.service" /etc/systemd/system/shopvivaliz-web-agent.service
systemd-analyze verify /etc/systemd/system/shopvivaliz-web-agent.service
ln -sfn "$release" /opt/shopvivaliz-web-agent/current.new
mv -Tf /opt/shopvivaliz-web-agent/current.new /opt/shopvivaliz-web-agent/current
systemctl daemon-reload
systemctl enable shopvivaliz-web-agent.service >/dev/null
systemctl restart shopvivaliz-web-agent.service
healthy=false
for attempt in $(seq 1 15); do
  if systemctl is-active --quiet shopvivaliz-web-agent.service &&
      curl -fsS --connect-timeout 1 --max-time 3 http://127.0.0.1:5598/health >/dev/null; then
    healthy=true
    break
  fi
  sleep 1
done
if [ "$healthy" != true ]; then
  echo 'New Web Agent release unhealthy: rolling back to previous local release' >&2
  if [ -n "$previous_release" ]; then
    ln -sfn "$previous_release" /opt/shopvivaliz-web-agent/current.rollback
    mv -Tf /opt/shopvivaliz-web-agent/current.rollback /opt/shopvivaliz-web-agent/current
    systemctl restart shopvivaliz-web-agent.service || true
    curl -fsS --connect-timeout 2 --max-time 5 http://127.0.0.1:5598/health || true
  else
    systemctl stop shopvivaliz-web-agent.service || true
  fi
  exit 5
fi
echo "WEB_AGENT_LOCAL_RELEASE_VERIFIED=true"
curl -fsS --connect-timeout 2 --max-time 5 http://127.0.0.1:5598/health
