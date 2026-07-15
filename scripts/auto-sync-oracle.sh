#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/site-shopvivaliz}"
BASE="${BASE:-main}"
LOG_DIR="$ROOT/logs"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/auto-sync-oracle.log"
STAMP="$(date +%Y%m%d-%H%M%S)"

log(){ echo "[$(date -Is)] $*" | tee -a "$LOG"; }

cd "$ROOT"
log "Inicio sync Oracle"

git fetch origin

if [ -n "$(git status --porcelain)" ]; then
  BRANCH="auto/oracle-$STAMP"
  git checkout -b "$BRANCH"
  git add -A
  git commit -m "chore: auto sync oracle $STAMP"
  git push -u origin "$BRANCH"
  gh pr create --draft --base "$BASE" --head "$BRANCH" --title "Auto sync Oracle $STAMP" --body "Sincronizacao automatica do servidor Oracle. Revisar antes de mesclar."
  log "Alteracoes locais enviadas para PR: $BRANCH"
  exit 0
fi

git checkout "$BASE"
git pull --ff-only origin "$BASE"
log "Oracle atualizado por fast-forward"
