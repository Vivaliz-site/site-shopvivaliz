from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows" / "mlrr-production-ops-bridge.yml"


def test_bridge_is_manual_auditable_and_fail_closed() -> None:
    text = WF.read_text(encoding="utf-8")
    trigger = text.split("permissions:", 1)[0]

    assert "workflow_dispatch:" in trigger
    assert "issue_comment:" in trigger
    assert "push:" not in trigger
    assert "schedule:" not in trigger

    assert "environment: production" in text
    assert "- preflight" in text
    assert "- rollout" in text
    assert "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]" in text

    assert "github.event.issue.number == 1586" in text
    assert "github.event.comment.user.login == 'fredmourao-ai'" in text
    assert "startsWith(github.event.comment.body, '/mlrr ')" in text
    assert r"/mlrr\s+operation=(prepare|cutover|validate|shadow|preflight|rollout)" in text

    assert "gh auth status --hostname github.com" in text
    assert "gh repo clone Vivaliz-site/mercadolivre-returns-recovery" in text
    assert "mktemp -d" in text
    assert "git fetch origin main" not in text
    assert "repo=/home/ubuntu/mercadolivre-returns-recovery" not in text
    assert "export GITHUB_REF_NAME=main" in text

    rollout = text.split('if [ "$MLRR_OPERATION" = rollout ]; then', 1)[1]
    assert rollout.index("scripts/mlrr-production-ops.sh prepare") < rollout.index(
        "scripts/mlrr-production-ops.sh shadow"
    )
    assert rollout.index("scripts/mlrr-production-ops.sh shadow") < rollout.index(
        "scripts/mlrr-production-ops.sh validate"
    )
    assert rollout.index("scripts/mlrr-production-ops.sh validate") < rollout.index(
        "scripts/mlrr-production-ops.sh preflight"
    )
    assert 'scripts/mlrr-production-ops.sh "$MLRR_OPERATION"' in text

    assert "EXECUTION_PROVENANCE_HMAC_KEY" in text
    assert "emit-execution-provenance.py" in text
    assert "|| true" not in text
    assert "set +e" not in text
    assert "ML_CLIENT_SECRET" not in text
    assert "return-review" not in text


if __name__ == "__main__":
    test_bridge_is_manual_auditable_and_fail_closed()
    print("MLRR production operations bridge contract: PASS")
