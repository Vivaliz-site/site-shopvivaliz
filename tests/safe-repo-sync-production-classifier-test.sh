#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
source_script="$root/scripts/safe-repo-sync.sh"
classifier="$root/scripts/should-deploy-production.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
repo="$tmp/repo"
current="$tmp/current"
shared="$tmp/shared"
mkdir -p "$repo/scripts" "$current" "$shared"
git -C "$repo" init -q
git -C "$repo" config user.email test@example.invalid
git -C "$repo" config user.name test
cat > "$repo/git-auto-sync.py" <<'PY'
#!/usr/bin/env python3
raise SystemExit(0)
PY
chmod +x "$repo/git-auto-sync.py"
cat > "$repo/scripts/deploy-production.sh" <<'DEPLOY'
#!/usr/bin/env bash
set -euo pipefail
: "${DEPLOY_MARKER:?}"
: "${CURRENT_ROOT:?}"
: "${SHOPVIVALIZ_DEPLOY_REPO_DIR:?}"
printf 'deploy\n' >> "$DEPLOY_MARKER"
git -C "$SHOPVIVALIZ_DEPLOY_REPO_DIR" rev-parse HEAD > "$CURRENT_ROOT/.release-sha"
DEPLOY
chmod +x "$repo/scripts/deploy-production.sh"
printf 'base\n' > "$repo/index.php"
git -C "$repo" add .
git -C "$repo" commit -qm base
base_sha="$(git -C "$repo" rev-parse HEAD)"
printf '%s\n' "$base_sha" > "$current/.release-sha"

mkdir -p "$repo/docs"
printf 'docs only\n' > "$repo/docs/runbook.md"
git -C "$repo" add docs/runbook.md
git -C "$repo" commit -qm docs-only
marker="$tmp/deploy.marker"
DEPLOY_MARKER="$marker" ROOT="$repo" SHARED_ROOT="$shared" CURRENT_ROOT="$current" \
  SYNC_RUNNER_PATH="$repo/git-auto-sync.py" DEPLOY_CLASSIFIER="$classifier" \
  bash "$source_script" > "$tmp/docs.log"
test "$(wc -l < "$marker")" -eq 1
docs_sha="$(git -C "$repo" rev-parse HEAD)"
test "$(cat "$current/.release-sha")" = "$docs_sha"
grep -Fq 'delta exige producao' "$tmp/docs.log"

printf 'runtime change\n' > "$repo/scripts/runtime-worker.php"
git -C "$repo" add scripts/runtime-worker.php
git -C "$repo" commit -qm runtime-change
DEPLOY_MARKER="$marker" ROOT="$repo" SHARED_ROOT="$shared" CURRENT_ROOT="$current" \
  SYNC_RUNNER_PATH="$repo/git-auto-sync.py" DEPLOY_CLASSIFIER="$classifier" \
  bash "$source_script" > "$tmp/runtime.log"
test "$(wc -l < "$marker")" -eq 2
runtime_sha="$(git -C "$repo" rev-parse HEAD)"
test "$(cat "$current/.release-sha")" = "$runtime_sha"
grep -Fq 'delta exige producao' "$tmp/runtime.log"

echo 'safe repo sync production parity: ok'
