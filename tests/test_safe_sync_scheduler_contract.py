from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
installer = (ROOT / "scripts/install-auto-sync-oracle.sh").read_text(encoding="utf-8")
smoke = (ROOT / "scripts/production-smoke-test.sh").read_text(encoding="utf-8")
workflow = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
service = (ROOT / "deploy/systemd/shopvivaliz-sync-safe.service").read_text(encoding="utf-8")
systemd_installer = (ROOT / "scripts/install-safe-sync-service.sh").read_text(encoding="utf-8")
safe_sync = (ROOT / "scripts/safe-repo-sync.sh").read_text(encoding="utf-8")
assert "command -v crontab" in installer
assert "shopvivaliz-sync-safe.timer" in installer
assert "shopvivaliz-sync-safe.timer" in smoke
assert "install-safe-sync-service.sh" in workflow
assert 'SOURCE_ROOT="$current" SEED_REPO="$root/repo" SYNC_ROOT="$root/sync-repo"' in workflow
assert "NoNewPrivileges=false" in service
assert "NoNewPrivileges=true" not in service
assert 'chown ubuntu:ubuntu "$DEPLOY_LOG_FILE"' in systemd_installer
assert 'chmod 0644 "$DEPLOY_LOG_FILE"' in systemd_installer

assert "WorkingDirectory=/home/ubuntu/shopvivaliz-deploy/sync-repo" in service
assert "Environment=ROOT=/home/ubuntu/shopvivaliz-deploy/sync-repo" in service
assert "Environment=SHOPVIVALIZ_DEPLOY_REPO_DIR=/home/ubuntu/shopvivaliz-deploy/sync-repo" in service
assert 'SYNC_ROOT="${SYNC_ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"' in systemd_installer
assert 'SEED_REPO="${SEED_REPO:-/home/ubuntu/shopvivaliz-deploy/repo}"' in systemd_installer
assert 'SAFE_SYNC_RUN_ON_INSTALL="${SAFE_SYNC_RUN_ON_INSTALL:-true}"' in systemd_installer
assert 'if [[ "$SAFE_SYNC_RUN_ON_INSTALL" == \'true\' ]]' in systemd_installer
assert "SAFE_SYNC_INITIAL_RUN=DEFERRED" in systemd_installer
assert 'SAFE_SYNC_RUN_ON_INSTALL=false SOURCE_ROOT="$current" SEED_REPO="$root/repo" SYNC_ROOT="$root/sync-repo"' in workflow
assert 'git clone --quiet --no-tags --single-branch --branch "$SYNC_BRANCH"' in systemd_installer
assert 'ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"' in safe_sync
assert 'exec env SHOPVIVALIZ_DEPLOY_REPO_DIR="$ROOT" "$DEPLOY_RUNNER" main' in safe_sync

assert 'systemctl show --property=Result --value "$SERVICE_NAME"' in systemd_installer
assert "if [[ \"$service_result\" != 'success' ]]" in systemd_installer
assert 'systemctl is-active --quiet "$TIMER_NAME"' in systemd_installer
assert 'systemctl is-enabled --quiet "$TIMER_NAME"' in systemd_installer
assert 'systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager' in systemd_installer
assert 'SAFE_SYNC_INSTALL=PASS' in systemd_installer
assert 'DEPLOY_CLASSIFIER="${DEPLOY_CLASSIFIER:-$ROOT/scripts/should-deploy-production.sh}"' in safe_sync
assert 'git -C "$ROOT" diff --name-only "$active_sha" "$repo_sha" | bash "$DEPLOY_CLASSIFIER"' in safe_sync
assert 'if [ "$should_deploy" = false ]' in safe_sync
assert 'nao exige deploy; release preservada' in safe_sync
assert 'if [ "$should_deploy" != true ]' in safe_sync
print("safe sync scheduler contract: ok")
assert '|| true' not in systemd_installer
