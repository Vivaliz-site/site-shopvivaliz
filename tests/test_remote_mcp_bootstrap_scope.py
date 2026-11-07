from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "remote_mcp_bootstrap_scope", ROOT / "scripts/remote_mcp_bootstrap_scope.py"
)
assert SPEC and SPEC.loader
scope = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(scope)


def test_browser_only_changes_use_targeted_backend_job():
    assert scope.is_browser_only([
        "scripts/setup-remote-control-browser-mcp.sh",
        "remote-control-browser-mcp/server.py",
        "deploy/systemd/shopvivaliz-browser-atendimento-mcp.service",
        "deploy/systemd/shopvivaliz-browser-dev-mcp.service",
    ])
    assert scope.is_browser_only([
        ".github/workflows/remote-control-mcp-bootstrap.yml",
        "scripts/remote_mcp_bootstrap_scope.py",
        "tests/test_remote_mcp_bootstrap_scope.py",
    ])


def test_display_recovery_changes_are_backend_only():
    assert scope.is_browser_only([
        "scripts/setup-remote-control-browser-mcp.sh",
        "scripts/setup-shopvivaliz-display-recovery.sh",
        "scripts/shopvivaliz-xvfb99-prepare.sh",
        "ops/systemd/shopvivaliz-xvfb99.service",
        "tests/test_shopvivaliz_display_recovery.py",
    ])


def test_merged_browser_health_patch_does_not_trigger_four_host_bootstrap():
    # Regression: PR #2849 failed a full bootstrap because the browser-only
    # regression test path was mistakenly excluded from the scope allowlist.
    assert scope.is_browser_only([
        "remote-control-browser-mcp/server.py",
        "tests/remote-control-browser-mcp-test.py",
        "docs/knowledge/chatgpt-access-parity.md",
        "docs/superpowers/plans/2026-11-05-mcp-gui-write-access.md",
        "docs/superpowers/specs/2026-11-05-mcp-gui-write-access-design.md",
        "remote-control-mcp/SPEC.md",
    ])
    assert not scope.is_browser_only([
        "remote-control-browser-mcp/server.py",
        "tests/remote-control-browser-mcp-test.py",
        "remote-control-mcp/server.py",
    ])


def test_controller_or_windows_changes_still_require_four_host_bootstrap():
    assert not scope.is_browser_only(["remote-control-mcp/server.py"])
    assert not scope.is_browser_only(["scripts/setup-remote-control-windows.ps1"])
    assert not scope.is_browser_only([
        "remote-control-browser-mcp/server.py", "scripts/setup-remote-control-access.sh"
    ])
    assert not scope.is_browser_only(["deploy/systemd/shopvivaliz-remote-control-mcp.service"])


def test_restricted_backend_checkpoint_changes_never_use_four_host_bootstrap():
    assert scope.is_backend_controller_only([
        "remote-control-mcp/server.py",
        "remote-control-mcp/SPEC.md",
        "scripts/continuity/checkpoint_update_atomic.py",
        "tests/test_orquestrador_atomic_checkpoint.py",
        "tests/remote-control-mcp-test.py",
        "scripts/remote_mcp_bootstrap_scope.py",
        "tests/test_remote_mcp_bootstrap_scope.py",
        ".github/workflows/remote-control-mcp-bootstrap.yml",
        "scripts/should-deploy-production.sh",
        "scripts/setup-remote-control-access.sh",
        "tests/should-deploy-production-test.sh",
    ])
    assert scope.is_backend_controller_only(["remote-control-mcp/server.py"])
    assert scope.is_backend_controller_only([
        "scripts/remote_mcp_bootstrap_scope.py",
        "tests/test_remote_mcp_bootstrap_scope.py",
    ])
    assert not scope.is_backend_controller_only([
        "scripts/remote_mcp_bootstrap_scope.py",
    ])
    assert not scope.is_backend_controller_only([
        "tests/test_remote_mcp_bootstrap_scope.py",
    ])
    # Regression (2026-11-02): the approved PR #3550 modified the handler,
    # production E2E probe, and their tests. The classifier misrouted that
    # valid backend-only diff to a disabled four-host legacy bootstrap, so the
    # live backend kept stale code even though GitHub reported workflow green.
    assert scope.is_backend_controller_only([
        "remote-control-mcp/server.py",
        "tests/remote-control-mcp-test.py",
        "scripts/task_continuity_e2e.py",
        "tests/test_task_continuity_e2e.py",
    ])
    assert scope.is_backend_controller_only([
        "remote-control-mcp/server.py",
        "scripts/remote_mcp_bootstrap_scope.py",
        "tests/test_remote_mcp_bootstrap_scope.py",
    ])
    assert not scope.is_backend_controller_only([
        "scripts/task_continuity_e2e.py",
        "tests/test_task_continuity_e2e.py",
    ])
    assert not scope.is_backend_controller_only([
        "remote-control-mcp/server.py",
        "scripts/task_continuity_e2e.py",
        "deploy/systemd/shopvivaliz-remote-control-mcp.service",
    ])
    assert scope.is_backend_controller_only([
        "remote-control-mcp/server.py",
        "scripts/setup-remote-control-access.sh",
        "tests/remote-control-mcp-test.py",
        ".github/workflows/remote-control-mcp-bootstrap.yml",
        "scripts/remote_mcp_bootstrap_scope.py",
        "tests/test_remote_mcp_bootstrap_scope.py",
    ])
    assert not scope.is_backend_controller_only(["scripts/continuity/checkpoint_update_atomic.py"])
    assert not scope.is_backend_controller_only(["remote-control-mcp/server.py", "scripts/continuity/checkpoint_update_atomic.py", "scripts/setup-remote-control-windows.ps1"])
    assert not scope.is_backend_controller_only(["remote-control-mcp/server.py", "scripts/continuity/checkpoint_update_atomic.py", "deploy/systemd/shopvivaliz-remote-control-mcp.service"])
    assert not scope.is_backend_controller_only(["../remote-control-mcp/server.py"])



def test_marina_grant_worker_pr_3739_reaches_backend_installer():
    # RED in production: merge of #3739 modified precisely these three files,
    # yet scope=FULL, so all install jobs were skipped on push main.
    changed = [
        ".github/workflows/remote-control-mcp-ci.yml",
        "remote-control-mcp/server.py",
        "remote-control-mcp/test_marina_admin_gate.py",
    ]
    assert scope.is_backend_controller_only(changed)
    assert not scope.is_browser_only(changed)
    # A test or CI change alone is not permission to restart the MCP.
    assert not scope.is_backend_controller_only([
        ".github/workflows/remote-control-mcp-ci.yml",
        "remote-control-mcp/test_marina_admin_gate.py",
    ])
    assert not scope.is_backend_controller_only([
        "remote-control-mcp/server.py",
        "deploy/systemd/shopvivaliz-remote-control-mcp.service",
    ])



def test_classifier_self_repair_routes_to_exactly_one_installer():
    # RED on push SHA f21534a7: both backend_controller_only and browser_only
    # ran for only the classifier and its own regression test.
    files = [
        "scripts/remote_mcp_bootstrap_scope.py",
        "tests/test_remote_mcp_bootstrap_scope.py",
    ]
    assert scope.is_backend_controller_only(files)
    assert scope.is_browser_only(files)  # raw allowlists intentionally overlap
    assert scope.classify_bootstrap(files) == (False, True, False)
    assert scope.classify_bootstrap(["remote-control-browser-mcp/server.py"]) == (True, False, False)
    assert scope.classify_bootstrap(["remote-control-mcp/server.py"]) == (False, True, False)
    assert scope.classify_bootstrap([
        "remote-control-mcp/server.py",
        "deploy/systemd/shopvivaliz-remote-control-mcp.service",
    ]) == (False, False, False)


def test_mixed_authorized_mcp_sources_use_exact_one_backend_combined_job():
    files = [
        "remote-control-mcp/server.py",
        "remote-control-browser-mcp/server.py",
        "tests/remote-control-mcp-test.py",
        "tests/remote-control-browser-mcp-test.py",
        "remote-control-browser-mcp/tests/test_account_auth_gate.py",
        "docs/knowledge/chatgpt-access-parity.md",
    ]
    assert scope.is_backend_browser_combined(files)
    assert scope.classify_bootstrap(files) == (False, False, True)
    # This was the merged PR #3767: before the fix, its workflow passed
    # the scope job but every installer was SKIPPED.
    assert not scope.is_backend_controller_only(files)
    assert not scope.is_browser_only(files)
    for bad in (
        ["remote-control-mcp/server.py", "remote-control-browser-mcp/server.py",
         "scripts/setup-remote-control-windows.ps1"],
        ["remote-control-mcp/server.py", "remote-control-browser-mcp/server.py",
         "deploy/systemd/shopvivaliz-remote-control-mcp.service"],
        ["remote-control-mcp/server.py", "remote-control-browser-mcp/server.py",
         "../secrets"],
        ["remote-control-mcp/server.py", "remote-control-browser-mcp/server.py",
         r"C:\\Windows\\Temp"],
        ["remote-control-mcp/server.py", "remote-control-browser-mcp/server.py",
         "remote-control-browser-mcp/server.py"],
        ["remote-control-mcp/server.py"],
        ["remote-control-browser-mcp/server.py"],
    ):
        assert not scope.is_backend_browser_combined(bad), bad
        assert scope.classify_bootstrap(bad)[2] is False


def test_mixed_mcp_workflow_has_only_live_backend_installer_targets():
    yml = (ROOT / ".github/workflows/remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
    block = yml.split("  backend_browser_combined:", 1)[1].split("\n  bootstrap:", 1)[0]
    assert "needs.scope.outputs.backend_browser_combined == 'true'" in block
    assert "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]" in block
    assert "install-controller-code-only" in block
    assert "scripts/setup-remote-control-browser-mcp.sh" in block
    assert "BACKEND_DUAL_SURFACE_MCP_INSTALL=PASS" in block
    assert "cmp remote-control-mcp/server.py /opt/shopvivaliz-remote-control/server.py" in block
    for forbidden in ("shopvivaliz-free-a1", "10.0.1.112", "install-target",
                      "Bootstrap Windows reverse SSH relays", "ssh -o", "scp -o"):
        assert forbidden not in block, forbidden
    assert 'if: ${{ false }}' in yml  # retired source must stay disabled


def test_code_only_installer_leaves_config_and_other_hosts_untouched():
    script = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
    narrow = script.split("install_controller_code_only() {", 1)[1].split("\ninstall_target() {", 1)[0]
    assert 'BACKEND_MCP_ONLY_RESTRICTED_INSTALL=PASS' in narrow
    assert 'systemctl restart "$SERVICE"' in narrow
    assert 'systemctl restart "$BROWSER_SERVICE"' in narrow
    for forbidden in ("mcp-token", "service.env", "ssh-keygen", "authorized_keys", "known_hosts", "daemon-reload", "install-target", "useradd", "visudo"):
        assert forbidden not in narrow, forbidden




def test_empty_or_untrusted_diff_fails_closed():
    for bad in ([], [""], ["../secrets"], [r"C:\external\path"], ["/etc/passwd"]):
        assert not scope.is_browser_only(bad)


def test_workflow_disables_retired_full_bootstrap_and_keeps_backend_only():
    yml = (ROOT / ".github/workflows/remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
    assert "needs: scope" in yml
    assert "group: shopvivaliz-remote-control-backend-bootstrap" in yml
    assert "cancel-in-progress: false" in yml
    assert "browser_only: " in yml
    assert "needs.scope.outputs.browser_only == 'true'" in yml
    assert "needs.scope.outputs.backend_controller_only == 'true'" in yml
    assert "Install only the backend MCP services" in yml
    assert "scripts/remote_mcp_bootstrap_scope.py" in yml
    assert "sudo -n bash scripts/setup-remote-control-browser-mcp.sh" in yml
    assert "Bootstrap Windows reverse SSH relays" in yml
    assert "DECOMMISSIONED_SOURCE_GUARD_20261029" in yml
    assert "if: ${{ false }}" in yml


if __name__ == "__main__":
    test_browser_only_changes_use_targeted_backend_job()
    test_display_recovery_changes_are_backend_only()
    test_merged_browser_health_patch_does_not_trigger_four_host_bootstrap()
    test_controller_or_windows_changes_still_require_four_host_bootstrap()
    test_restricted_backend_checkpoint_changes_never_use_four_host_bootstrap()
    test_marina_grant_worker_pr_3739_reaches_backend_installer()
    test_classifier_self_repair_routes_to_exactly_one_installer()
    test_mixed_authorized_mcp_sources_use_exact_one_backend_combined_job()
    test_mixed_mcp_workflow_has_only_live_backend_installer_targets()
    test_code_only_installer_leaves_config_and_other_hosts_untouched()
    test_empty_or_untrusted_diff_fails_closed()
    test_workflow_disables_retired_full_bootstrap_and_keeps_backend_only()
    # Run the actual production classifier contract on every PR quality gate.
    # This proves that the restricted MCP-only merge cannot launch a web deploy.
    import subprocess
    subprocess.run(["bash", str(ROOT / "tests" / "should-deploy-production-test.sh")], check=True, timeout=20)
    print("REMOTE_CONTROL_BOOTSTRAP_SCOPE_TEST=PASS")
