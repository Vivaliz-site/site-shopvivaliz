from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

service = (ROOT / "deploy/systemd/shopvivaliz-sync-safe.service").read_text(encoding="utf-8")
installer = (ROOT / "scripts/install-safe-sync-service.sh").read_text(encoding="utf-8")
safe_sync = (ROOT / "scripts/safe-repo-sync.sh").read_text(encoding="utf-8")
auto_sync = (ROOT / "scripts/auto-sync-oracle.sh").read_text(encoding="utf-8")
cron_installer = (ROOT / "scripts/install-auto-sync-oracle.sh").read_text(encoding="utf-8")
deploy = (ROOT / "scripts/deploy-production.sh").read_text(encoding="utf-8")
pipeline = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
smoke = (ROOT / "scripts/production-smoke-test.sh").read_text(encoding="utf-8")

dedicated = "/home/ubuntu/shopvivaliz-deploy/sync-repo"
shared_task = "/home/ubuntu/shopvivaliz-deploy/repo"

assert f"WorkingDirectory={dedicated}" in service
assert f"Environment=ROOT={dedicated}" in service
assert f"Environment=SHOPVIVALIZ_DEPLOY_REPO_DIR={dedicated}" in service
assert f"WorkingDirectory={shared_task}" not in service

assert 'SYNC_ROOT="${SYNC_ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"' in installer
assert 'SEED_REPO="${SEED_REPO:-/home/ubuntu/shopvivaliz-deploy/repo}"' in installer
assert 'status --porcelain' in installer
assert 'merge --ff-only' in installer
assert 'git clone --quiet --no-tags --single-branch --branch "$SYNC_BRANCH"' in installer

assert 'ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"' in safe_sync
assert 'ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"' in auto_sync
assert 'ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"' in cron_installer
assert 'SHOPVIVALIZ_DEPLOY_REPO_DIR="$ROOT"' in safe_sync
assert 'SHOPVIVALIZ_DEPLOY_REPO_DIR:-/home/ubuntu/shopvivaliz-deploy/repo' in deploy

assert 'SOURCE_ROOT="$current"' in pipeline
assert 'SEED_REPO="$root/repo"' in pipeline
assert 'SYNC_ROOT="$root/sync-repo"' in pipeline
assert f"repo='{dedicated}'" in smoke

print("dedicated safe sync checkout contract: ok")
