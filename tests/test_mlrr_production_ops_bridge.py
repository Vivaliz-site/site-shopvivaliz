from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows" / "mlrr-production-ops-bridge.yml"


def test_bridge_is_manual_auditable_and_fail_closed() -> None:
    text = WF.read_text(encoding="utf-8")
    trigger = text.split("permissions:", 1)[0]

    assert "workflow_dispatch:" in trigger
    assert "workflow_call:" in trigger
    assert "push:" not in trigger
    assert "schedule:" not in trigger

    assert "environment: production" in text
    assert "- preflight" in text
    assert "- rollout" in text
    assert "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]" in text

    # The source host was retired on 2026-10-29. Its permanent job guard
    # supersedes the old issue-comment authorization; it must never execute.
    jobs = text.split("jobs:\n", 1)[1]
    assert re.findall(r"(?m)^  ([A-Za-z_][\w-]*):\s*$", jobs) == ["operate"]
    header = jobs.split("  operate:\n", 1)[1].split("    steps:\n", 1)[0]
    conditions = re.findall(r"(?m)^    if:\s*([^\n]+)$", header)
    assert len(conditions) == 1, "retired job must have one unambiguous guard"
    assert re.fullmatch(r"\$\{\{\s*false\s*\}\}\s*(?:#.*)?", conditions[0])
    assert "RETIRED_SOURCE_JOB_GUARD_20261029" in header
    assert r"/mlrr\s+operation=(prepare|cutover|validate|shadow|preflight|rollout)" in text

    assert "uses: actions/checkout@v4" in text
    assert "persist-credentials: false" in text
    assert "gh auth status --hostname github.com" in text
    assert "GH_CONFIG_DIR=/home/ubuntu/.config/gh" in text
    assert "gh repo clone Vivaliz-site/mercadolivre-returns-recovery" in text
    assert "mktemp -d" in text
    assert "git fetch origin main" not in text
    assert "repo=/home/ubuntu/mercadolivre-returns-recovery" not in text
    assert "site_repo=/home/ubuntu/shopvivaliz-deploy/repo" not in text
    assert "export GITHUB_REF_NAME=main" in text

    assert 'conf_dir="$HOME/.config/shopvivaliz"' in text
    assert 'if [ ! -s "$key_file" ]; then' in text
    assert 'openssl rand -hex 32 > "$key_file"' in text
    assert 'chmod 600 "$key_file"' in text
    assert 'provenance_script="$GITHUB_WORKSPACE/scripts/emit-execution-provenance.py"' in text
    assert 'test -f "$provenance_script"' in text

    clone_at = text.index("gh repo clone Vivaliz-site/mercadolivre-returns-recovery")
    started_at = text.index('provenance STARTED ""')
    prepare_at = text.index("scripts/mlrr-production-ops.sh prepare")
    assert clone_at < started_at < prepare_at

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
