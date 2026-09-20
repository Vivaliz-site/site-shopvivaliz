#!/usr/bin/env bash
set -Eeuo pipefail

STATE=/var/lib/shopvivaliz-browser
META="$STATE/sessions/mfa/metadata.json"
SERVICE=shopvivaliz-browser-mfa.service

if [[ -f "$META" ]]; then
  expires="$(jq -r '.expires_epoch // 0' "$META")"
  now="$(date +%s)"
  if [[ "$expires" =~ ^[0-9]+$ ]] && (( expires > 0 && now >= expires )); then
    echo "BROWSER_SESSION_EXPIRED=true"
    if systemctl is-active --quiet "$SERVICE"; then
      systemctl stop "$SERVICE"
    fi
    rm -rf -- "$STATE/sessions/mfa"
  fi
fi

find "$STATE/artifacts" -mindepth 1 -maxdepth 1 -type d -mtime +7 -print0 2>/dev/null |
  while IFS= read -r -d '' path; do rm -rf -- "$path"; done
