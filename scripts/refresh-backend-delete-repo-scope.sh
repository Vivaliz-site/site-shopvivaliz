#!/usr/bin/env bash
set -Eeuo pipefail

export GH_CONFIG_DIR=/home/ubuntu/.config/gh
unset GH_TOKEN GITHUB_TOKEN

gh auth status --hostname github.com >/dev/null

if gh api -i user 2>/dev/null | tr -d '\r' | grep -Eiq '^x-oauth-scopes:.*delete_repo'; then
  echo "BACKEND_DELETE_REPO_SCOPE=ALREADY_PRESENT"
  exit 0
fi

previous_clipboard="$(gh config get clipboard 2>/dev/null || printf 'enabled')"
case "$previous_clipboard" in
  enabled|disabled) ;;
  *) previous_clipboard=enabled ;;
esac

restore_clipboard() {
  gh config set clipboard "$previous_clipboard" >/dev/null 2>&1 || true
}
trap restore_clipboard EXIT INT TERM

# gh 2.9x+ copies OAuth device codes to the clipboard by default. The
# headless wrapper must read the one-time code from the pseudo-TTY log, so
# disable clipboard copying only for this refresh and restore it on exit.
gh config set clipboard disabled >/dev/null
gh auth refresh -h github.com -s delete_repo
