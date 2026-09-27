#!/usr/bin/env bash
set -Eeuo pipefail

export GH_CONFIG_DIR=/home/ubuntu/.config/gh
unset GH_TOKEN GITHUB_TOKEN

gh auth status --hostname github.com >/dev/null

if gh api -i user 2>/dev/null | tr -d '\r' | grep -Eiq '^x-oauth-scopes:.*delete_repo'; then
  echo "BACKEND_DELETE_REPO_SCOPE=ALREADY_PRESENT"
  exit 0
fi

exec gh auth refresh -h github.com -s delete_repo
