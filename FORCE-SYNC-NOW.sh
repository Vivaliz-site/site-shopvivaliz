#!/bin/bash
set -euo pipefail

# Manual emergency synchronization for execution on the production VM only.
# The normal path is the protected deployment pipeline.

REPO_DIR="${REPO_DIR:-/home/ubuntu/site-shopvivaliz}"
cd "$REPO_DIR"

if [ "$(id -u)" -eq 0 ]; then
    echo "Nao execute este script como root" >&2
    exit 1
fi

current_sha="$(git rev-parse HEAD)"
backup_branch="server-backup/manual-sync-$(date -u +%Y%m%d-%H%M%S)"
git branch "$backup_branch" "$current_sha"

git fetch --no-tags origin main
target_sha="$(git rev-parse origin/main)"
git cat-file -e "${target_sha}^{commit}"

git reset --hard "$target_sha"

if [ "$(git rev-parse HEAD)" != "$target_sha" ]; then
    echo "Falha ao confirmar o commit sincronizado" >&2
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Arquivos rastreados ficaram alterados apos a sincronizacao" >&2
    exit 1
fi

echo "Sincronizacao concluida"
echo "Backup: $backup_branch"
echo "Commit: $target_sha"
