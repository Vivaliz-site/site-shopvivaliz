#!/usr/bin/env bash
set -Eeuo pipefail

# Install only the narrow cleanup service. Never modify the active release,
# other MCP services, Git clones, their data or SSH/RustDesk transports.
test "$(id -u)" -eq 0 || { echo "ERROR=root_required" >&2; exit 2; }
src="${1:-scripts/agent_clone_lifecycle.py}"
test -f "$src" || { echo "ERROR=clone_lifecycle_source_missing" >&2; exit 3; }
test -f deploy/systemd/shopvivaliz-agent-clone-cleanup.service
test -f deploy/systemd/shopvivaliz-agent-clone-cleanup.timer

python3 -m py_compile "$src"
install -d -m 0755 /usr/local/lib/shopvivaliz
install -m 0755 "$src" /usr/local/lib/shopvivaliz/agent_clone_lifecycle.py
for kind in service timer; do
  install -m 0644 "deploy/systemd/shopvivaliz-agent-clone-cleanup.${kind}" "/etc/systemd/system/shopvivaliz-agent-clone-cleanup.${kind}"
done

systemctl daemon-reload
systemctl enable --now shopvivaliz-agent-clone-cleanup.timer
# One read/safe-only initial sweep. The tool deletes only clones registered to
# CONCLUIDO checkpoints, and refuses active, dirty, locked, unpushed clones.
systemctl start shopvivaliz-agent-clone-cleanup.service
systemctl is-enabled --quiet shopvivaliz-agent-clone-cleanup.timer
systemctl is-active --quiet shopvivaliz-agent-clone-cleanup.timer
echo "AGENT_CLONE_LIFECYCLE_INSTALLED=PASS"
