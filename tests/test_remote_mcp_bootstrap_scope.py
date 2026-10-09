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


def test_controller_or_windows_changes_still_require_four_host_bootstrap():
    assert not scope.is_browser_only(["remote-control-mcp/server.py"])
    assert not scope.is_browser_only(["scripts/setup-remote-control-windows.ps1"])
    assert not scope.is_browser_only([
        "remote-control-browser-mcp/server.py", "scripts/setup-remote-control-access.sh"
    ])
    assert not scope.is_browser_only(["deploy/systemd/shopvivaliz-remote-control-mcp.service"])


def test_empty_or_untrusted_diff_fails_closed():
    for bad in ([], [""], ["../secrets"], [r"C:\external\path"], ["/etc/passwd"]):
        assert not scope.is_browser_only(bad)


def test_workflow_keeps_full_bootstrap_and_browser_only_separate():
    yml = (ROOT / ".github/workflows/remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
    assert "needs: scope" in yml
    assert "group: shopvivaliz-remote-control-backend-bootstrap" in yml
    assert "cancel-in-progress: false" in yml
    assert "browser_only: " in yml
    assert "needs.scope.outputs.browser_only == 'true'" in yml
    assert "needs.scope.outputs.browser_only != 'true'" in yml
    assert "scripts/remote_mcp_bootstrap_scope.py" in yml
    assert "sudo -n bash scripts/setup-remote-control-browser-mcp.sh" in yml
    assert "Bootstrap Windows reverse SSH relays" in yml


if __name__ == "__main__":
    test_browser_only_changes_use_targeted_backend_job()
    test_controller_or_windows_changes_still_require_four_host_bootstrap()
    test_empty_or_untrusted_diff_fails_closed()
    test_workflow_keeps_full_bootstrap_and_browser_only_separate()
    print("REMOTE_CONTROL_BOOTSTRAP_SCOPE_TEST=PASS")
