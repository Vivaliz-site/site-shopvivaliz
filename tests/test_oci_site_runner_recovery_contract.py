from pathlib import Path


WORKFLOW = (
    Path(__file__).resolve().parents[1]
    / ".github"
    / "workflows"
    / "oci-site-runner-recovery.yml"
)
TEXT = WORKFLOW.read_text(encoding="utf-8")


def _step_block(name: str) -> str:
    marker = f"      - name: {name}\n"
    start = TEXT.index(marker)
    next_step = TEXT.find("\n      - name: ", start + len(marker))
    return TEXT[start:] if next_step == -1 else TEXT[start:next_step]


def test_precheck_exports_fail_safe_recovery_decision() -> None:
    block = _step_block("Verify OS through OCI Run Command before recovery")
    assert "id: precheck" in block
    assert 'recovery_required = False' in block
    assert 'recovery_reason = "healthy_listener"' in block
    assert "elif not listener_present:" in block
    assert 'recovery_reason = "listener_absent"' in block
    assert 'recovery_reason = "stale_orphan_worker"' in block
    assert 'handle.write(f"recovery_required=' in block
    assert 'handle.write(f"recovery_reason=' in block


def test_healthy_runner_path_is_explicit_noop() -> None:
    block = _step_block("Record healthy runner no-op")
    assert "if: steps.precheck.outputs.recovery_required != 'true'" in block
    assert "OCI_RUNNER_RECOVERY=SKIPPED_HEALTHY" in block


def test_destructive_recovery_steps_require_positive_precheck() -> None:
    guarded_steps = (
        "Cancel obsolete production pipeline reservations",
        "Gracefully reboot site A1 through OCI",
        "Wait for Oracle agent after reboot",
        "Dispatch exactly one canonical production deploy",
    )
    guard = "if: steps.precheck.outputs.recovery_required == 'true'"
    for step in guarded_steps:
        assert guard in _step_block(step), step
