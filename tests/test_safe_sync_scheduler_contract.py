from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
installer = (ROOT / "scripts/install-auto-sync-oracle.sh").read_text(encoding="utf-8")
smoke = (ROOT / "scripts/production-smoke-test.sh").read_text(encoding="utf-8")
workflow = (ROOT / ".github/workflows/master-production-pipeline.yml").read_text(encoding="utf-8")
assert "command -v crontab" in installer
assert "shopvivaliz-sync-safe.timer" in installer
assert "shopvivaliz-sync-safe.timer" in smoke
assert "install-safe-sync-service.sh" in workflow
assert 'ROOT="$root/repo"' in workflow
print("safe sync scheduler contract: ok")
