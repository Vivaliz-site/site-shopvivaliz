#!/usr/bin/env bash
# Restore the protected display dependency before browser MCP bridge deployment.
# Does not restart Chromium or modify either corporate profile.
set -Eeuo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR=graphical_bootstrap_requires_root" >&2
  exit 11
fi
for tool in Xvfb xauth mcookie xhost systemctl runuser; do
  command -v "$tool" >/dev/null 2>&1 || {
    echo "ERROR=missing_graphical_tool:$tool" >&2
    exit 12
  }
done
runuser_bin="$(command -v runuser)"
case "$runuser_bin" in /*) ;; *) echo "ERROR=runuser_binary_not_absolute" >&2; exit 12 ;; esac
source_unit="ops/systemd/shopvivaliz-xvfb99.service"
source_helper="scripts/shopvivaliz-xvfb99-prepare.sh"
test -s "$source_unit" && test -s "$source_helper" || {
  echo "ERROR=graphical_bootstrap_source_missing" >&2
  exit 13
}

# The continuity bridge already owns :99 when this unit is installed.
# Fail closed rather than launching a second Xvfb or disturbing its cookie.
display_provider=shopvivaliz-xvfb99.service
if systemctl cat shopvivaliz-virtual-display.service >/dev/null 2>&1; then
  if ! systemctl is-active --quiet shopvivaliz-virtual-display.service; then
    echo "ERROR=canonical_virtual_display_not_active" >&2
    exit 14
  fi
  if [ ! -S /tmp/.X11-unix/X99 ]; then
    echo "ERROR=canonical_display_socket_missing" >&2
    exit 15
  fi
  display_provider=shopvivaliz-virtual-display.service
else
  install -d -m 0755 /usr/local/libexec
  install -m 0755 "$source_helper" /usr/local/libexec/shopvivaliz-xvfb99-prepare.sh
  install -m 0644 "$source_unit" /etc/systemd/system/shopvivaliz-xvfb99.service
fi

for unit in shopvivaliz-dev-browser shopvivaliz-atendimento-browser shopvivaliz-chatgpt-browser shopvivaliz-authenticated-browser-wm; do
  install -d -m 0755 "/etc/systemd/system/$unit.service.d"
  cat > "/etc/systemd/system/$unit.service.d/96-xvfb99-dependency.conf" <<DROPIN
[Unit]
Requires=$display_provider
After=$display_provider
DROPIN
done

# Apply only to the auxiliary profile, never to Dev or Atendimento cookies.
# X11 permits this exact local OS principal; no Xauthority is copied.
install -d -m 0755 /etc/systemd/system/shopvivaliz-atendimento-mcp-browser.service.d
cat > /etc/systemd/system/shopvivaliz-atendimento-mcp-browser.service.d/96-x0-local-acl.conf <<DROPIN
[Unit]
After=display-manager.service
Wants=display-manager.service

[Service]
ExecStartPre=+${runuser_bin} -u fredconsole -- /usr/bin/env DISPLAY=:0 XAUTHORITY=/home/fredconsole/.Xauthority /usr/bin/xhost +SI:localuser:fredrdp
DROPIN

systemctl daemon-reload
if [ "$display_provider" = shopvivaliz-virtual-display.service ]; then
  # The canonical provider is already active; retire only the competing unit.
  if systemctl cat shopvivaliz-xvfb99.service >/dev/null 2>&1; then
    systemctl disable --now shopvivaliz-xvfb99.service
  fi
else
  systemctl enable --now shopvivaliz-xvfb99.service
fi
systemctl is-active --quiet "$display_provider"
test -S /tmp/.X11-unix/X99
# No browser/session restart: Xvfb, dependencies and ACL are applied at
# the next service start. Existing verified Chromium profiles stay intact.
echo "SHOPVIVALIZ_GRAPHICAL_AUTH_BOOTSTRAP=PASS"
