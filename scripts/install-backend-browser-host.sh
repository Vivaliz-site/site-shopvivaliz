#!/usr/bin/env bash
set -Eeuo pipefail

EXPECTED_HOST=always-free-arm-1787907847-26
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$ROOT/ops/browser-host"
APP=/opt/shopvivaliz-browser-host
STATE=/var/lib/shopvivaliz-browser
CONF=/etc/shopvivaliz-browser
SERVICE_USER=shopbrowser

[[ "$(hostname)" == "$EXPECTED_HOST" ]] || { echo "wrong host" >&2; exit 20; }
[[ "$EUID" -eq 0 ]] || { echo "root required" >&2; exit 21; }
[[ -f "$SOURCE/package.json" ]] || { echo "browser host bundle missing" >&2; exit 22; }

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends   ca-certificates curl jq xvfb openbox x11vnc novnc websockify fonts-liberation

if ! command -v tailscale >/dev/null 2>&1; then
  tmp_install="$(mktemp)"
  trap 'rm -f "$tmp_install"' EXIT
  curl --proto '=https' --tlsv1.2 -fsSL https://tailscale.com/install.sh -o "$tmp_install"
  sh "$tmp_install"
  rm -f "$tmp_install"
  trap - EXIT
fi
systemctl enable --now tailscaled.service

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
  useradd --system --create-home --home-dir "$STATE" --shell /usr/sbin/nologin "$SERVICE_USER"
fi

install -d -o root -g root -m 0755 "$APP"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0750   "$STATE" "$STATE/pw-browsers" "$STATE/profiles" "$STATE/sessions" "$STATE/artifacts"
install -d -o root -g "$SERVICE_USER" -m 0750 "$CONF"

install -o root -g root -m 0644 "$SOURCE/package.json" "$APP/package.json"
install -o root -g root -m 0755 "$SOURCE/browser-session.sh" "$APP/browser-session.sh"
install -o root -g root -m 0755 "$SOURCE/browser-cleanup.sh" "$APP/browser-cleanup.sh"
install -o root -g root -m 0644 "$SOURCE/browser-smoke.mjs" "$APP/browser-smoke.mjs"

cd "$APP"
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --omit=dev --no-audit --no-fund
PLAYWRIGHT_BROWSERS_PATH="$STATE/pw-browsers" "$APP/node_modules/.bin/playwright" install-deps chromium
runuser -u "$SERVICE_USER" -- env   HOME="$STATE"   PLAYWRIGHT_BROWSERS_PATH="$STATE/pw-browsers"   "$APP/node_modules/.bin/playwright" install chromium

cat > "$CONF/mfa.env" <<'EOF'
BROWSER_PROFILE=mfa-default
BROWSER_SESSION_ORIGIN=human-mfa
BROWSER_SESSION_TASK=interactive-auth
BROWSER_SESSION_TTL_SECONDS=7200
EOF
chown root:"$SERVICE_USER" "$CONF/mfa.env"
chmod 0640 "$CONF/mfa.env"

install -o root -g root -m 0644 "$SOURCE/shopvivaliz-browser-mfa.service" /etc/systemd/system/shopvivaliz-browser-mfa.service
install -o root -g root -m 0644 "$SOURCE/shopvivaliz-browser-cleanup.service" /etc/systemd/system/shopvivaliz-browser-cleanup.service
install -o root -g root -m 0644 "$SOURCE/shopvivaliz-browser-cleanup.timer" /etc/systemd/system/shopvivaliz-browser-cleanup.timer

systemctl daemon-reload
systemctl enable --now shopvivaliz-browser-cleanup.timer
systemctl disable shopvivaliz-browser-mfa.service >/dev/null 2>&1 || :

echo "BROWSER_HOST_INSTALL=PASS"
echo "BROWSER_HOST_PLAYWRIGHT_VERSION=$(node -p "require('$APP/node_modules/playwright/package.json').version")"
echo "BROWSER_HOST_TAILSCALE_INSTALLED=yes"
