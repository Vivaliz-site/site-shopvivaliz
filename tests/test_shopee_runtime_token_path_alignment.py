from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
workflow = (ROOT / ".github/workflows/merge-runtime-credential-union.yml").read_text(encoding="utf-8")
readiness = (ROOT / "scripts/maintenance/marketplace_publication_readiness.php").read_text(encoding="utf-8")
health = (ROOT / ".github/workflows/shopee-runtime-health.yml").read_text(encoding="utf-8")
canonical = "/home/ubuntu/shopvivaliz-deploy/shared/shopee-tokens.json"
assert "'shopee': shared / 'shopee-tokens.json'" in workflow
assert canonical in readiness
assert canonical in health
assert "storage/private/shopee-tokens.json" in readiness  # legacy PHP fallback remains available
print("shopee runtime token path alignment: ok")
