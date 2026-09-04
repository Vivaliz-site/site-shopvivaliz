from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / "scripts" / "production-functional-audit.sh"
SMOKE = ROOT / "tests" / "storefront-smoke.sh"
RULES = ROOT / "docs" / "knowledge" / "agent-rules.md"
WORKFLOW = ROOT / ".github" / "workflows" / "production-functional-audit.yml"


def test() -> None:
    assert AUDIT.exists(), "production functional audit script is missing"
    audit = AUDIT.read_text(encoding="utf-8")
    smoke = SMOKE.read_text(encoding="utf-8")
    rules = RULES.read_text(encoding="utf-8")
    workflow = WORKFLOW.read_text(encoding="utf-8")

    required_audit_markers = [
        "PRODUCTION_FUNCTIONAL_AUDIT=PASS",
        "/api/melhorenvio/shipping-check-v2.php",
        "/api/agent/integrations-health.php",
        "/api/orders/health.php",
        "/api/catalog/products.php",
        "shipping_options",
        "mercado_pago",
        "melhor_envio",
    ]
    for marker in required_audit_markers:
        assert marker in audit, f"missing functional audit marker: {marker}"

    assert '[[ "$code" == 200 || "$code" == 207 ]]' in audit, "integration health must accept HTTP 207 when critical providers are connected"
    assert "for key in ('mercado_pago','melhor_envio','olist_tiny')" in audit, "audit must use the current Olist integration key"
    assert "summary = r.get('summary') or {}" in audit and "summary.get('failed')" in audit, "audit must accept optional attention while rejecting failed providers"
    assert "r.get('ok') is not True" not in audit, "audit must not require optional integrations to be configured"

    required_workflow_markers = [
        "SHOPVIVALIZ_VM_SSH_KEY",
        "SHOPVIVALIZ_VM_KNOWN_HOSTS",
        "SITE_A1_HOST: 163.176.103.253",
        "StrictHostKeyChecking=yes",
        "shopvivaliz-free-a1",
        "production-functional-audit.sh",
        "REMOTE_STAGE",
        "rm -rf -- \"$stage\"",
    ]
    for marker in required_workflow_markers:
        assert marker in workflow, f"production audit workflow missing remote-probe safety marker: {marker}"
    assert "run: bash scripts/production-functional-audit.sh" not in workflow, "GitHub-hosted runner must not probe Cloudflare directly"

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
