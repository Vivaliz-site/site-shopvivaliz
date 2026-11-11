#!/usr/bin/env bash
# Instala o gateway Access->MCP. Nao toca DNS, tunel nem Cloudflare.
# O servico so inicia se /var/lib/shopvivaliz-remote-control/access-gateway.json existir (0600, root).
set -Eeuo pipefail
test "$(id -u)" = 0 || { echo "ERROR=root_required" >&2; exit 2; }
SRC="${1:-remote-control-access-gateway}"
VERIFIER="${2:-marina-direct-mcp/cloudflare_access_auth.py}"
UNIT_SRC="${3:-deploy/systemd/shopvivaliz-rc-access-gateway.service}"
DEST=/opt/shopvivaliz-rc-access-gateway
for f in "$SRC/gateway.py" "$VERIFIER" "$UNIT_SRC"; do test -f "$f" || { echo "ERROR=missing:$f" >&2; exit 3; }; done
python3 -c 'import jwt, cryptography' 2>/dev/null || { echo "ERROR=python3_jwt_cryptography_missing" >&2; exit 4; }
install -d -m 0755 "$DEST"
install -m 0644 "$SRC/gateway.py" "$DEST/gateway.py"
install -m 0644 "$VERIFIER" "$DEST/cloudflare_access_auth.py"
install -m 0644 "$UNIT_SRC" /etc/systemd/system/shopvivaliz-rc-access-gateway.service
python3 -m py_compile "$DEST/gateway.py" "$DEST/cloudflare_access_auth.py"
systemctl daemon-reload
systemctl enable shopvivaliz-rc-access-gateway.service >/dev/null
if [ -f /var/lib/shopvivaliz-remote-control/access-gateway.json ]; then
  systemctl restart shopvivaliz-rc-access-gateway.service
  sleep 2
  systemctl is-active shopvivaliz-rc-access-gateway.service
else
  echo "INSTALLED_NOT_STARTED=access-gateway.json ausente"
fi