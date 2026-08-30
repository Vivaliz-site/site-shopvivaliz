from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RECOVERY = ROOT / ".github" / "workflows" / "critical-storefront-integration-recovery.yml"
CONTROL = "ops/recover-critical-storefront-integrations.json"


def test() -> None:
    recovery = RECOVERY.read_text(encoding="utf-8")

    assert CONTROL in recovery
    for marker in (
        "MERCADOPAGO_ACCESS_TOKEN_VALUE",
        "MERCADOPAGO_PUBLIC_KEY_VALUE",
        "MERCADOPAGO_WEBHOOK_SECRET_VALUE",
        "MELHORENVIO_ACCESS_TOKEN_VALUE",
        "scripts/update-production-env.py",
        "chmod 640",
    ):
        assert marker in recovery, f"missing recovery marker: {marker}"

    assert "SHOPVIVALIZ_OLIST_TOKEN_FILE" in recovery
    assert "olist_oauth_runtime_bootstrap=verified" in recovery
    assert "production-functional-audit.sh" in recovery


if __name__ == "__main__":
    test()
    print("critical integration recovery workflow contract: PASS")
