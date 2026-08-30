from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
QUALITY = ROOT / ".github" / "workflows" / "quality-gate.yml"
MASTER = ROOT / ".github" / "workflows" / "master-production-pipeline.yml"
INSTALLER = ROOT / "scripts" / "install-catalog-sync-service.sh"


def test() -> None:
    quality = QUALITY.read_text(encoding="utf-8")
    master = MASTER.read_text(encoding="utf-8")
    installer = INSTALLER.read_text(encoding="utf-8")

    assert "grep -q '163.176.103.253' .github/workflows/master-production-pipeline.yml" in quality
    assert "! grep -q '163.176.103.253' .github/workflows/master-production-pipeline.yml" not in quality
    assert "137.131.156.17" not in master, "active production pipeline must not target retired E2"
    for test_cmd in (
        "python3 tests/test_production_functional_audit_contract.py",
        "python3 tests/test_catalog_available_only_contract.py",
        "python3 tests/test_melhorenvio_integration_health_contract.py",
        "python3 tests/test_critical_integration_recovery_workflows.py",
        "python3 tests/test_production_pipeline_runtime_contract.py",
    ):
        assert test_cmd in quality, f"quality gate must run {test_cmd}"
    assert "install-catalog-sync-service.sh" in master, (
        "release activation must reconcile Olist/Shopee token-renewer services on every deploy"
    )
    assert "shopvivaliz-token-renewer.service" in installer
    assert "shopvivaliz-shopee-token-renewer.service" in installer


if __name__ == "__main__":
    test()
    print("production pipeline runtime contract: PASS")
