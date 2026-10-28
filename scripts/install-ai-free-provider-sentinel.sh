#!/usr/bin/env bash
set -euo pipefail
host="$(hostname -s)"
case "$host" in
  always-free-arm-1787907847-26|shopvivaliz-free-a1|marina-a1-4c24g) ;;
  *) echo "UNSUPPORTED_HOST=$host" >&2; exit 6 ;;
esac
if [ "$(id -u)" -ne 0 ]; then
  echo "ROOT_REQUIRED" >&2
  exit 7
fi
repo_root="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
source="$repo_root/scripts/ai-free-provider-sentinel.py"
unit_source="$repo_root/deploy/systemd"
test -s "$source"
test -s "$unit_source/shopvivaliz-ai-free-guard.service"
test -s "$unit_source/shopvivaliz-ai-free-guard.timer"
python3 -c 'import ast, pathlib, sys; ast.parse(pathlib.Path(sys.argv[1]).read_text())' "$source"
install -d -o root -g root -m 0755 /usr/local/lib/shopvivaliz
install -d -o root -g root -m 0700 /var/lib/shopvivaliz-ai-free-guard
install -o root -g root -m 0755 "$source" /usr/local/lib/shopvivaliz/ai-free-provider-sentinel.py
for name in shopvivaliz-ai-free-guard.service shopvivaliz-ai-free-guard.timer; do
  install -o root -g root -m 0644 "$unit_source/$name" "/etc/systemd/system/$name"
done
systemd-analyze verify /etc/systemd/system/shopvivaliz-ai-free-guard.service \
  /etc/systemd/system/shopvivaliz-ai-free-guard.timer >/dev/null
systemctl daemon-reload
systemctl enable --now shopvivaliz-ai-free-guard.timer
systemctl start shopvivaliz-ai-free-guard.service
systemctl is-active --quiet shopvivaliz-ai-free-guard.timer
echo "AI_FREE_SENTINEL_INSTALL=PASS HOST=$host"
