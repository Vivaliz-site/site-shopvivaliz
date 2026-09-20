from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows" / "mlrr-production-ops-bridge.yml"


def test_bridge_is_fail_closed_and_one_shot_shadow_is_explicit() -> None:
    text = WF.read_text(encoding="utf-8")
    trigger = text.split("permissions:", 1)[0]
    assert "workflow_dispatch:" in trigger
    assert "push:" in trigger
    assert 'branches: [main]' in trigger
    assert '".github/workflows/mlrr-production-ops-bridge.yml"' in trigger
    assert "schedule:" not in trigger
    assert "environment: production" in text
    assert "- shadow" in text
    assert "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]" in text
    assert "repo=/home/ubuntu/mercadolivre-returns-recovery" in text
    assert "git merge --ff-only origin/main" in text
    assert 'test -z "$(git status --porcelain)"' in text
    assert 'export GITHUB_REF_NAME=main' in text
    assert 'if [ "$GITHUB_EVENT_NAME" = push ]; then' in text
    assert 'scripts/mlrr-production-ops.sh prepare' in text
    assert 'scripts/mlrr-production-ops.sh shadow' in text
    assert 'scripts/mlrr-production-ops.sh validate' in text
    assert 'scripts/mlrr-production-ops.sh "$MLRR_OPERATION"' in text
    assert "EXECUTION_PROVENANCE_HMAC_KEY" in text
    assert "emit-execution-provenance.py" in text
    assert "ML_CLIENT_SECRET" not in text
    assert "return-review" not in text
    assert "SRF7" not in text


if __name__ == "__main__":
    test_bridge_is_fail_closed_and_one_shot_shadow_is_explicit()
    print("MLRR production operations bridge contract: PASS")
