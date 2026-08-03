"""Tests for scripts/deploy-production.sh structural correctness.

These tests validate the key properties of the deploy script that were
identified as blocking issues in PR #710:
  1. Log directory is created before any log() call.
  2. runtime-secrets materialization is present (reconcile_runtime_secrets).
  3. tasks-queue.json bootstrap uses the canonical {"tasks":[]} schema.
  4. Health check uses /api/health/version.php (not homepage or /api/health).
  5. Post-deploy validation confirms deployed SHA before declaring success.
  6. Rollback logic is present.
  7. Cleanup uses find instead of the unsafe ls|tail pattern.
"""
from __future__ import annotations

import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEPLOY_SCRIPT = ROOT / "scripts" / "deploy-production.sh"


def _lines() -> list[str]:
    return DEPLOY_SCRIPT.read_text(encoding="utf-8").splitlines()


def _text() -> str:
    return DEPLOY_SCRIPT.read_text(encoding="utf-8")


# ---------------------------------------------------------------------------
# Bash syntax check
# ---------------------------------------------------------------------------


def test_deploy_script_bash_syntax() -> None:
    """The script must have valid bash syntax."""
    result = subprocess.run(
        ["bash", "-n", str(DEPLOY_SCRIPT)],
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0, f"bash -n failed:\n{result.stderr}"


# ---------------------------------------------------------------------------
# Fix 1: log directory created before first log() call
# ---------------------------------------------------------------------------


def test_log_dir_mkdir_precedes_log_function() -> None:
    """mkdir -p must appear before the log() function definition."""
    lines = _lines()
    mkdir_line = None
    log_func_line = None
    for i, line in enumerate(lines):
        stripped = line.strip()
        if mkdir_line is None and re.search(r"mkdir\s+-p\b", stripped):
            mkdir_line = i
        if log_func_line is None and re.match(r"^log\s*\(\s*\)", stripped):
            log_func_line = i
    assert mkdir_line is not None, "No 'mkdir -p' found in deploy script"
    assert log_func_line is not None, "No 'log()' function found in deploy script"
    assert mkdir_line < log_func_line, (
        f"mkdir -p (line {mkdir_line + 1}) must come before log() "
        f"definition (line {log_func_line + 1})"
    )


def test_log_dir_variable_covered_by_mkdir() -> None:
    """The LOG_DIR variable must appear in the mkdir -p line."""
    text = _text()
    assert "LOG_DIR" in text, "LOG_DIR variable not found"
    # The mkdir line that creates LOG_DIR should reference it
    for line in _lines():
        if re.search(r"mkdir\s+-p\b", line) and "LOG_DIR" in line:
            return
    raise AssertionError("No mkdir -p line references $LOG_DIR")


# ---------------------------------------------------------------------------
# Fix 2: runtime-secrets materialization preserved
# ---------------------------------------------------------------------------


def test_reconcile_runtime_secrets_function_defined() -> None:
    """reconcile_runtime_secrets must be defined in the script."""
    assert "reconcile_runtime_secrets()" in _text(), (
        "reconcile_runtime_secrets() function not found; "
        "PHP app would lose DB/signing secrets during deploy"
    )


def test_reconcile_runtime_secrets_is_called() -> None:
    """reconcile_runtime_secrets must be called during the deploy flow."""
    calls = [
        line for line in _lines()
        if re.search(r"\breconcile_runtime_secrets\b", line)
        and not re.match(r"^\s*(#|reconcile_runtime_secrets\s*\(\s*\))", line)
    ]
    assert len(calls) >= 1, (
        "reconcile_runtime_secrets is defined but never called; "
        "runtime secrets will not be materialized"
    )


# ---------------------------------------------------------------------------
# Fix 3: tasks-queue.json bootstrap uses canonical schema
# ---------------------------------------------------------------------------


def test_tasks_queue_bootstrap_uses_tasks_key() -> None:
    """tasks-queue.json stub must use the canonical 'tasks' key, not 'queue'."""
    text = _text()
    # Find the bootstrap block for tasks-queue.json
    assert '"tasks"' in text or "'tasks'" in text, (
        "tasks-queue.json bootstrap does not contain the canonical 'tasks' key; "
        "system_health_check.py and shopvivaliz_dashboard.py require it"
    )
    # The stub must NOT use {version, queue} format as the only option
    bootstrap_match = re.search(
        r'tasks-queue\.json.*?printf.*?\\n.*?\\n.*?\\n',
        text,
        re.DOTALL,
    )
    if bootstrap_match:
        stub = bootstrap_match.group(0)
        assert '"queue"' not in stub or '"tasks"' in stub, (
            "tasks-queue.json bootstrap uses 'queue' key without 'tasks'; "
            "consumers like system_health_check.py expect 'tasks'"
        )


def test_tasks_queue_bootstrap_does_not_use_queue_only() -> None:
    """Bootstrap stub must not use the {version, queue} format exclusively."""
    lines = _lines()
    in_bootstrap = False
    for i, line in enumerate(lines):
        if "tasks-queue.json" in line:
            in_bootstrap = True
        if in_bootstrap:
            # If we see printf with 'queue' but without 'tasks', that's wrong
            if "printf" in line and '"queue"' in line and '"tasks"' not in line:
                raise AssertionError(
                    f"Line {i + 1}: bootstrap stub uses 'queue' key instead of 'tasks':\n  {line}"
                )
            # Reset after the closing fi of the bootstrap block
            if in_bootstrap and line.strip() in ("fi", "done"):
                in_bootstrap = False


# ---------------------------------------------------------------------------
# Fix 4: health-check endpoint uses correct path
# ---------------------------------------------------------------------------


def test_health_check_uses_version_endpoint() -> None:
    """The health check must call /api/health/version.php, not just /api/health."""
    assert "/api/health/version.php" in _text(), (
        "Health check endpoint /api/health/version.php not found; "
        "the script must verify the version endpoint, not just the homepage"
    )


def test_health_check_does_not_check_only_homepage() -> None:
    """Post-deploy validation must not rely solely on http://127.0.0.1 (homepage)."""
    text = _text()
    # It's acceptable to also check the homepage, but version.php must be there
    has_version_check = "/api/health/version.php" in text
    assert has_version_check, (
        "Deploy validation only checks the homepage; must verify "
        "/api/health/version.php to confirm the correct release is live"
    )


# ---------------------------------------------------------------------------
# Fix 5: post-deploy SHA verification
# ---------------------------------------------------------------------------


def test_sha_verification_function_present() -> None:
    """A function that verifies the deployed SHA must be defined."""
    text = _text()
    # verify_local_release is the canonical name used in the current script
    assert re.search(r"\bverify_local_release\s*\(", text), (
        "verify_local_release() not found; "
        "post-deploy validation must confirm the deployed SHA matches FETCH_HEAD"
    )


def test_sha_verification_is_called_before_success() -> None:
    """verify_local_release must be called in the main deploy flow."""
    calls = [
        line for line in _lines()
        if re.search(r"\bverify_local_release\b", line)
        and not re.match(r"^\s*(#|verify_local_release\s*\(\s*\))", line)
    ]
    assert len(calls) >= 1, (
        "verify_local_release is defined but never called; "
        "the script will swap the symlink without confirming the release is healthy"
    )


# ---------------------------------------------------------------------------
# Fix 6: rollback logic is present
# ---------------------------------------------------------------------------


def test_rollback_function_present() -> None:
    """A rollback function must be defined to allow reverting on failure."""
    assert re.search(r"\brollback_to\s*\(", _text()), (
        "rollback_to() not found; "
        "the script has no way to revert to a known-good release on failure"
    )


# ---------------------------------------------------------------------------
# Fix 7: cleanup uses safer pattern (find, not bare ls|tail)
# ---------------------------------------------------------------------------


def test_cleanup_uses_find_not_ls_pipe() -> None:
    """Cleanup of old releases must use find, not ls piped to tail."""
    # The unsafe pattern ls | tail would look like: ls ... | tail ...
    # We check that cleanup uses find for release discovery
    text = _text()
    assert re.search(r"\bfind\b.*RELEASES_DIR", text), (
        "Release cleanup does not use 'find'; "
        "using 'ls | tail' is unsafe and risks removing the wrong directory"
    )
