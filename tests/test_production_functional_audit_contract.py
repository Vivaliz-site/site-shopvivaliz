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
        "/api/health/payment-queue.php",
        "worker_ok",
        "oldest_queued_age_seconds",
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
        "SITE_A1_HOST: 127.0.0.1",
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


def test_marina_audit_must_probe_the_destination_not_public_origin() -> None:
    """A remote shell on Marina is not proof that HTTPS requests reached Marina."""
    marina = (ROOT / ".github" / "workflows" / "production-functional-audit-marina.yml").read_text(encoding="utf-8")
    audit = AUDIT.read_text(encoding="utf-8")

    assert "BASE_URL: https://shopvivaliz.com.br" in marina
    assert "AUDIT_TARGET_IP: 127.0.0.1" in marina, "target must route to Marina loopback, not Cloudflare/public source"
    assert "export AUDIT_TARGET_IP" in marina, "remote audit must receive destination pin"
    assert "AUDIT_TARGET_IP" in audit and "shopvivaliz.com.br:443:" in audit
    assert audit.count('"${CURL_PIN_ARGS[@]}"') >= 3, "GET, POST and integration health must all use the target route"
    assert "url_effective" in audit, "audited requests must prove their final effective URL"
    assert 'curl -q -sSL' not in audit, "do not follow third-party redirects carrying an agent authorization header"
    assert 'for path in / /catalogo/ /carrinho /checkout; do' in audit, "test canonical path directly without following redirects"


def test_marina_runner_uses_local_probe_path_without_public_ssh_fallback() -> None:
    marina = (ROOT / ".github" / "workflows" / "production-functional-audit-marina.yml").read_text(encoding="utf-8")

    assert 'MARINA_EXECUTION_MODE=local' in marina
    assert 'if [ "$MARINA_EXECUTION_MODE" = local ]; then' in marina
    assert 'install -m 700 scripts/production-functional-audit.sh "$REMOTE_STAGE/production-functional-audit.sh"' in marina
    assert 'bash "$stage/production-functional-audit.sh"' in marina
    assert 'case "$REMOTE_STAGE" in /tmp/production-functional-audit-marina-*)' in marina
    assert 'rm -rf -- "$REMOTE_STAGE"' in marina


if __name__ == "__main__":
    test()
    test_marina_audit_must_probe_the_destination_not_public_origin()
    print("production functional audit contract: PASS")
