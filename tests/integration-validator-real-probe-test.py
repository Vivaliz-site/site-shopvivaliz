from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
legacy = (ROOT / "scripts/validate-integrations.py").read_text(encoding="utf-8")
health = (ROOT / "includes/integration-health.php").read_text(encoding="utf-8")

for synthetic in ("99.8%", "2847", "47", "OPERACIONAL"):
    assert synthetic not in legacy, f"synthetic integration result remains: {synthetic}"

assert "includes/integration-health.php" in legacy, "legacy validator must delegate to real provider health"
assert "svih_check_all(false)" in legacy, "legacy validator must call the fail-closed provider probe"
assert "function svih_google_ads" in health, "Google Ads needs a provider-backed health function"
assert "googleads.googleapis.com/v25" in health, "Google Ads probe must use current v25 REST API"
assert "svih_google_ads()" in health, "central integration list must invoke real Google Ads health"
assert "'Google Ads',\n            'google_ads'" not in health, "Google Ads must not use config-only health"

print("integration validator real probe: PASS")

readiness = (ROOT / "scripts/google_ads_real_readiness.py").read_text(encoding="utf-8")
assert "python_package_missing=google-ads" not in readiness, "REST readiness must not require the optional Python SDK"
assert "GOOGLE_OAUTH_REFRESH_TOKEN" in readiness, "readiness must accept the canonical OAuth refresh token alias"
assert "GOOGLE_ADS_GA4_IMPORT_VERIFIED" in readiness, "GA4 import mode must require explicit verified-import evidence"

monitor = (ROOT / "scripts/google_ads_30day_real_monitor.py").read_text(encoding="utf-8")
for synthetic in ("BUDGET_DAILY", "Simularia fazer chamada a GA4 API", "RESULTADO REAL"):
    assert synthetic not in monitor, f"synthetic Google Ads monitor remains: {synthetic}"
assert "google_ads_real_readiness.py" in monitor, "legacy monitor must delegate to the real readiness probe"
