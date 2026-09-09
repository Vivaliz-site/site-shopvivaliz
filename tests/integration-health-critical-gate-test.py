from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
workflow = (ROOT / ".github" / "workflows" / "integrations-hourly.yml").read_text(encoding="utf-8")

expected = "critical_keys = {'olist_tiny', 'mercado_livre', 'mercado_pago', 'melhor_envio'}"
assert expected in workflow, "hourly gate must define the four critical commerce integrations"
assert "if not data.get('ok', False):" not in workflow, "optional attention must not fail the hourly critical gate"
assert "critical_issues" in workflow, "hourly gate must fail on critical integration issues"
assert "missing_critical" in workflow, "hourly gate must fail when a critical integration disappears"
print("integration-health-critical-gate-test: ok")
