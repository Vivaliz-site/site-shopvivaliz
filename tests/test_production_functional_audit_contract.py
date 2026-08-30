from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / "scripts" / "production-functional-audit.sh"
SMOKE = ROOT / "tests" / "storefront-smoke.sh"
RULES = ROOT / "docs" / "knowledge" / "agent-rules.md"


def test() -> None:
    assert AUDIT.exists(), "production functional audit script is missing"
    audit = AUDIT.read_text(encoding="utf-8")
    smoke = SMOKE.read_text(encoding="utf-8")
    rules = RULES.read_text(encoding="utf-8")

    required_audit_markers = [
        "PRODUCTION_FUNCTIONAL_AUDIT=PASS",
        "/api/melhorenvio/shipping-check-v2.php",
        "/api/agent/integrations-health.php",
        "/api/orders/health.php",
        "/api/catalog/products.php",
        "shipping_options",
        "mercado_pago",
        "melhor_envio",
        "olist_tiny",
    ]
    for marker in required_audit_markers:
        assert marker in audit, f"missing functional audit marker: {marker}"

    assert "r.get('ok')" not in audit, (
        "optional/non-storefront integrations must not make the critical storefront audit fail"
    )

    assert "LOCAL_CONTRACT_SMOKE=PASS" in smoke
    assert "Storefront smoke tests passed." not in smoke

    required_rule_markers = [
        "AUDITORIA_FUNCIONAL_PRODUCAO",
        "HTTP 200 nao prova funcionamento",
        "PRODUCTION_FUNCTIONAL_AUDIT=PASS",
        "qualquer falha critica",
    ]
    for marker in required_rule_markers:
        assert marker in rules, f"missing anti-false-positive rule: {marker}"


if __name__ == "__main__":
    test()
    print("production functional audit contract: PASS")
