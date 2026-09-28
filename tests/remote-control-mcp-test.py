#!/usr/bin/env python3
import importlib.util
import json
import os
import tempfile
import threading
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVER_PATH = ROOT / "remote-control-mcp" / "server.py"

spec = importlib.util.spec_from_file_location("remote_control_mcp", SERVER_PATH)
m = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(m)


class RemoteControlMcpTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        m.STATE_DIR = Path(self.tmp.name)
        m.DB_PATH = m.STATE_DIR / "state.db"
        m.SSH_KEY = m.STATE_DIR / "id_ed25519"
        m.KNOWN_HOSTS = m.STATE_DIR / "known_hosts"
        m.init_db()

    def tearDown(self):
        self.tmp.cleanup()

    def test_four_canonical_hosts(self):
        self.assertEqual(
            set(m.HOSTS),
            {"always-free-arm-1787907847-26", "shopvivaliz-free-a1", "Fred-Win", "KOCEPSV"},
        )

    def test_mcp_tools_include_privileged_and_durable_controls(self):
        names = {item["name"] for item in m.tool_specs()}
        for required in {
            "hosts_list", "host_health", "processes_list", "service_status",
            "service_action", "file_read", "file_list", "logs_tail",
            "admin_command_run", "task_submit", "task_status", "task_cancel", "audit_recent",
        }:
            self.assertIn(required, names)

    def test_admin_tools_are_annotated_mutating(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertFalse(specs["admin_command_run"]["annotations"]["readOnlyHint"])
        self.assertTrue(specs["admin_command_run"]["annotations"]["destructiveHint"])
        self.assertTrue(specs["host_health"]["annotations"]["readOnlyHint"])

    def test_secret_redaction(self):
        sample = "Authorization: Bearer abcdefghijklmnopqrstuvwxyz sk-projectsecret123456"
        redacted = m.redact_text(sample)
        self.assertNotIn("abcdefghijklmnopqrstuvwxyz", redacted)
        self.assertNotIn("projectsecret123456", redacted)
        self.assertIn("REDACTED", redacted)

    def test_run_host_command_tolerates_non_utf8_output(self):
        result = m.run_host_command(
            "always-free-arm-1787907847-26", "printf 'before\\xa2after'"
        )
        self.assertEqual(result["exit_code"], 0)
        self.assertIn("before", result["stdout"])
        self.assertIn("after", result["stdout"])

    def test_task_worker_tolerates_non_utf8_output(self):
        submitted = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26",
            "command": "printf 'before\\xa2after'",
            "timeout": 30,
        })
        m.STOP_EVENT.clear()
        worker = threading.Thread(target=m.task_worker, daemon=True)
        worker.start()
        deadline = time.monotonic() + 10
        status = None
        while time.monotonic() < deadline:
            status = m.execute_tool("task_status", {"task_id": submitted["task_id"]})
            if status["state"] in {"succeeded", "failed", "cancelled", "expired"}:
                break
            time.sleep(0.1)
        m.STOP_EVENT.set()
        worker.join(timeout=5)
        self.assertIsNotNone(status)
        self.assertEqual(status["state"], "succeeded", status.get("stderr"))
        self.assertIn("before", status["stdout"])
        self.assertIn("after", status["stdout"])

    def test_mcp_authorization_is_fail_closed_and_constant_time(self):
        self.assertTrue(m.is_authorized("Bearer test-token", "test-token"))
        self.assertFalse(m.is_authorized("", "test-token"))
        self.assertFalse(m.is_authorized("Bearer wrong-token", "test-token"))
        self.assertFalse(m.is_authorized("Bearer test-token", ""))

    def test_controller_bootstrap_generates_root_only_mcp_token(self):
        setup = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8")
        self.assertIn("openssl rand -hex 32", setup)
        self.assertIn("mcp-token", setup)
        self.assertIn("EnvironmentFile=/var/lib/shopvivaliz-remote-control/service.env", setup)
        self.assertIn("EnvironmentFile=/var/lib/shopvivaliz-remote-control/service.env", unit)

    def test_sensitive_file_paths_are_denied(self):
        for path in ["/home/ubuntu/.ssh/id_ed25519", "C:\\Users\\FRED\\.ssh\\id_rsa", "/app/.env"]:
            with self.assertRaises(PermissionError):
                m.deny_sensitive_path(path)

    def test_audit_does_not_store_raw_command(self):
        raw = "echo super-sensitive-command-value"
        aid = m.audit("admin_command_run", "shopvivaliz-free-a1", {"command": raw}, True, "ok")
        with m.db_conn() as db:
            row = db.execute("SELECT args_json FROM audit WHERE id=?", (aid,)).fetchone()
        payload = json.loads(row["args_json"])
        self.assertNotIn("command", payload)
        self.assertIn("command_sha256", payload)
        self.assertNotIn(raw, row["args_json"])

    def test_task_submit_persists_without_executing_inline(self):
        result = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26",
            "command": "printf test",
            "timeout": 30,
        })
        self.assertEqual(result["state"], "queued")
        with m.db_conn() as db:
            row = db.execute("SELECT state,command_sha256 FROM tasks WHERE id=?", (result["task_id"],)).fetchone()
        self.assertEqual(row["state"], "queued")
        self.assertTrue(row["command_sha256"])

    def test_invalid_host_is_rejected(self):
        with self.assertRaises(ValueError):
            m.validate_host("legacy-host")

    def test_linux_target_uses_privileged_sudo(self):
        m.SSH_KEY.write_text("x")
        m.KNOWN_HOSTS.write_text("x")
        inv = m.remote_invocation("shopvivaliz-free-a1", "id -u")
        self.assertIn("sudo -n bash", inv[-1])
        self.assertIn("shopvivaliz-remote@10.0.1.112", inv)

    def test_windows_uses_reverse_ssh_ports_not_tailscale(self):
        m.SSH_KEY.write_text("x")
        m.KNOWN_HOSTS.write_text("x")
        fred = m.remote_invocation("Fred-Win", "Get-Date")
        desk = m.remote_invocation("KOCEPSV", "Get-Date")
        for inv in (fred, desk):
            self.assertIn("powershell.exe", inv)
            self.assertIn("-EncodedCommand", inv)
            self.assertNotIn("tailscale", " ".join(inv).lower())
        self.assertIn("FRED@127.0.0.1", fred)
        self.assertIn("-p", fred)
        self.assertEqual(fred[fred.index("-p") + 1], "2222")
        self.assertIn("user@127.0.0.1", desk)
        self.assertIn("-p", desk)
        self.assertEqual(desk[desk.index("-p") + 1], "2223")


class BranchCoherenceTests(unittest.TestCase):
    def test_single_canonical_remote_control_runtime(self):
        for rel in (
            "remote-control-mcp/controller.py",
            "remote-control-mcp/install.sh",
            "remote-control-mcp/e2e.py",
        ):
            self.assertFalse((ROOT / rel).exists(), f"duplicate runtime must not exist: {rel}")


class BootstrapContractTests(unittest.TestCase):
    def test_linux_bootstrap_grants_privilege_only_to_dedicated_user(self):
        text = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        self.assertIn('REMOTE_USER="shopvivaliz-remote"', text)
        self.assertIn('ALL=(ALL) NOPASSWD: ALL', text)
        self.assertIn('from="10.0.0.0/8,100.64.0.0/10"', text)
        self.assertIn("PasswordAuthentication no", text)

    def test_controller_is_loopback_only(self):
        text = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_HOST=127.0.0.1", text)
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_PORT=5580", text)

    def test_controller_admin_runtime_is_not_filesystem_sandboxed(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8")
        setup = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        for text in (unit, setup):
            self.assertNotIn("ProtectSystem=full", text)
            self.assertNotIn("ProtectHome=read-only", text)
            self.assertNotIn("PrivateTmp=true", text)

    def test_windows_bootstrap_requires_administrator(self):
        text = (ROOT / "scripts" / "setup-remote-control-windows.ps1").read_text(encoding="utf-8")
        self.assertIn("administrator_required", text)
        self.assertIn("administrators_authorized_keys", text)
        self.assertIn("REMOTE_CONTROL_WINDOWS_KEY_INSTALL=PASS", text)
        self.assertIn("OpenSSH.Server~~~~0.0.1.0", text)
        self.assertIn("Add-WindowsCapability", text)
        self.assertIn("New-Service -Name sshd", text)
        self.assertIn("openssh_binary_missing_after_capability", text)

    def test_windows_openssh_recovery_helper_repairs_service(self):
        text = (ROOT / "scripts" / "windows-openssh-recovery.ps1").read_text(encoding="utf-8")
        self.assertIn("OpenSSH.Server~~~~0.0.1.0", text)
        self.assertIn("Add-WindowsCapability", text)
        self.assertIn("New-Service -Name sshd", text)
        self.assertIn("ssh-keygen.exe", text)
        self.assertIn("REMOTE_CONTROL_WINDOWS_SSHD=PASS", text)
        self.assertIn("openssh_binary_missing_after_capability", text)

    def test_bootstrap_workflow_is_single_complete_sequence(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertEqual(text.count("- name: Four-host live MCP health validation"), 1)
        self.assertEqual(text.count("- name: Persist sanitized bootstrap evidence"), 1)
        self.assertNotIn("          PY\\n          PY\\n", text)
        self.assertEqual(text.count("- name: Cleanup bootstrap material"), 1)
        self.assertIn("test \"$(grep -cv '^#' \"$tmp\")\" -ge 3", text)
        self.assertIn('sudo -n install -m 600 -o root -g root "$tmp" /var/lib/shopvivaliz-remote-control/known_hosts', text)
        self.assertIn("REMOTE_CONTROL_FOUR_HOST_E2E=PASS", text)

    def test_bootstrap_runs_on_controller_backend(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", text)
        self.assertIn("sudo -n bash scripts/setup-remote-control-access.sh install-controller remote-control-mcp/server.py", text)
        self.assertNotIn("ubuntu@10.0.1.38)", text)
        self.assertIn("ubuntu@10.0.1.112", text)

    def test_bootstrap_uses_reverse_ssh_for_windows(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("ssh_protocol_alive 2222", text)
        self.assertIn("ssh_protocol_alive 2223", text)
        self.assertNotIn("32222:127.0.0.1:2222", text)
        self.assertNotIn("32223:127.0.0.1:2223", text)
        self.assertIn("http://127.0.0.1:5558/mcp/tool/execute_command", text)
        self.assertNotIn("tailscale status --json", text)
        self.assertNotIn("</dev/tcp/$fred_ip/22", text)
        self.assertNotIn("</dev/tcp/$desk_ip/22", text)
        self.assertIn("'scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1'", text)
        self.assertIn("'scripts/desktopkocepsv-remote-bootstrap.ps1'", text)

    def test_fred_bootstrap_recovers_missing_2222_via_legacy_relay(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1:5557/mcp/tool/execute_command", text)
        self.assertIn("'scripts/ssh-tunnel-service-managed.ps1'", text)
        self.assertIn("'scripts/fredwin-remote-bootstrap.ps1'", text)
        self.assertIn("REMOTE_CONTROL_FRED_STAGE=PASS", text)
        self.assertIn("REMOTE_CONTROL_FRED_RELAY_UPGRADE_QUEUED=PASS", text)
        self.assertIn('REMOTE_CONTROL_{label}_SSHD_RECOVERY_QUEUED=PASS', text)
        self.assertIn("REMOTE_CONTROL_{label}_SSHD_RECOVERY_STAGE=PASS", text)
        self.assertIn("windows-openssh-recovery.ps1", text)
        self.assertIn("git show 'origin/main:scripts/windows-openssh-recovery.ps1'", text)
        self.assertIn("Start-Process -FilePath 'powershell.exe'", text)
        self.assertIn('peers = (("FRED", 5557), ("KOCEPSV", 5558))', text)
        self.assertNotIn("inner_b64", text)
        self.assertIn("ssh_protocol_alive() {", text)
        self.assertIn("ssh-keyscan -T 5 -p", text)
        self.assertIn("REMOTE_CONTROL_FRED_REVERSE_SSH=FAIL protocol_handshake_unavailable", text)

    def test_windows_controller_key_bootstraps_over_recovery_relays(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        start = text.index("# Bootstrap the controller public key")
        end = text.index("- name: Pin private host keys for controller", start)
        block = text[start:end]
        self.assertIn('"write_file"', block)
        self.assertIn("setup-remote-control-windows.ps1", block)
        self.assertIn("REMOTE_CONTROL_WINDOWS_KEY_INSTALL=PASS", block)
        self.assertIn("/var/lib/shopvivaliz-remote-control/id_ed25519", block)
        self.assertIn("REMOTE_CONTROL_CONTROLLER_KEY_AUTH=PASS", block)
        self.assertNotIn('remote-control-bootstrap.key" scripts/setup-remote-control-windows.ps1', block)
        self.assertNotIn(" scp -P \"$port\"", block)

    def test_kocepsv_bootstrap_stages_relay_scripts_before_async_restart(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("git fetch origin main", text)
        self.assertIn("'scripts/desktopkocepsv-ssh-tunnel-service-managed.ps1'", text)
        self.assertIn("'scripts/desktopkocepsv-remote-bootstrap.ps1'", text)
        self.assertIn('git show ("origin/main:" + $rel)', text)
        self.assertIn("REMOTE_CONTROL_KOCEPSV_STAGE=PASS", text)
        self.assertNotIn("git merge --ff-only origin/main", text)

    def test_four_host_e2e_requires_explicit_stage5_dispatch(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("run_e2e:", text)
        self.assertIn("default: false", text)
        self.assertIn("if: github.event_name == 'workflow_dispatch' && inputs.run_e2e == true", text)

    def test_remote_control_ci_push_covers_workflow_changes(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-ci.yml").read_text(encoding="utf-8")
        self.assertEqual(text.count("'.github/workflows/remote-control-mcp-*.yml'"), 2)

    def test_oci_bastion_workflow_recovers_fred_reverse_ssh(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1:5557/mcp/tool/execute_command", text)
        self.assertIn("scripts/fredwin-remote-bootstrap.ps1", text)
        self.assertIn("scripts/ssh-tunnel-service-managed.ps1", text)
        self.assertIn("REMOTE_CONTROL_FRED_RECOVERY_QUEUED=PASS", text)
        self.assertIn("REMOTE_CONTROL_FRED_REVERSE_SSH=PASS", text)
        self.assertIn("REMOTE_CONTROL_KOCEPSV_REVERSE_SSH=PASS", text)
        self.assertIn("REMOTE_CONTROL_{label}_SSHD_RECOVERY_QUEUED=PASS", text)
        self.assertIn("backend_ssh_protocol_alive() {", text)
        self.assertIn("ssh-keyscan -T 5 -p", text)

    def test_oci_bastion_elevates_kocepsv_sidecar_after_admin_ssh(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("& $path -Mode Ensure", text)
        self.assertIn("desktopkocepsv-remote-control-ssh-bridge.ps1 -Mode InstallTask", text)
        self.assertIn('if [ "$label" = "DESKTOP" ]; then', text)

    def test_oci_bastion_workflow_supports_remote_control_stages(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for action in (
            "action=bootstrap-remote-control-mcp",
            "action=e2e-remote-control-mcp",
            "action=runtime-proof-submit",
            "action=runtime-proof-verify",
            "action=secure-mcp-tunnel-probe",
            "action=secure-mcp-platform-ui-probe",
        ):
            self.assertIn(action, text)
        for marker in (
            "SECURE_MCP_TUNNEL_PROBE=PASS",
            "TUNNEL_CLIENT_INSTALLED=",
            "TUNNEL_SERVICE_ACTIVE=",
            "TUNNEL_ID_CONFIGURED=",
            "TUNNEL_RUNTIME_KEY_AVAILABLE=",
            "TUNNEL_ADMIN_KEY_AVAILABLE=",
            "TUNNEL_MCP_LOCAL_AUTH_AVAILABLE=",
            "TUNNEL_OUTBOUND_HTTPS=PASS",
        ):
            self.assertIn(marker, text)
        self.assertIn("REMOTE_CONTROL_KOCEPSV_SIDECAR=FAIL class=", text)
        sidecar_block = text[text.index('SIDE_B64='):text.index('REMOTE_CONTROL_STAGE4_WINDOWS_BOOTSTRAP=PASS')]
        self.assertIn("import base64, json, os, urllib.error, urllib.request", sidecar_block)
        self.assertIn("REMOTE_CONTROL_KOCEPSV_SIDECAR=FAIL class=controller_invocation", sidecar_block)
        self.assertIn("REMOTE_CONTROL_STAGE4_WINDOWS_BOOTSTRAP=PASS", text)
        self.assertIn("REMOTE_CONTROL_FOUR_HOST_E2E=PASS", text)
        self.assertIn("DURABLE_AFTER_DISCONNECT=PASS", text)
        self.assertIn("RUNTIME_GITHUB_DEPENDENCY=false", text)

    def test_oci_bastion_secure_tunnel_probe_is_structurally_intact(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        probe_name = "- name: Probe Secure MCP Tunnel prerequisites"
        next_name = "- name: Validate both VMs through RustDesk on Fred-Win"
        self.assertEqual(text.count(probe_name), 1)
        self.assertEqual(text.count(next_name), 1)
        probe_start = text.index(probe_name)
        next_start = text.index(next_name)
        self.assertLess(probe_start, next_start)
        probe = text[probe_start:next_start]
        self.assertLess(len(probe), 9000, "secure tunnel probe block unexpectedly swallowed later workflow steps")
        self.assertIn("CONTROL_PLANE_TUNNEL_ID=tunnel_[0-9a-f]{32}", probe)
        self.assertIn("tunnel_id=true", probe)
        self.assertIn("SECURE_MCP_TUNNEL_PROBE=PASS", probe)
        self.assertIn("REMOTE", probe)
        self.assertNotIn("{32}        shell: bash", probe)
    def test_secure_mcp_platform_ui_probe_contract(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        script = ROOT / "scripts" / "openai-secure-mcp-platform-probe.mjs"
        self.assertIn("action=secure-mcp-platform-ui-probe", workflow)
        self.assertIn("scripts/openai-secure-mcp-platform-probe.mjs", workflow)
        self.assertTrue(script.exists(), "Secure MCP Platform UI probe script missing")
        text = script.read_text(encoding="utf-8")
        self.assertIn("https://platform.openai.com/settings/organization/tunnels", text)
        for marker in (
            "OPENAI_TUNNEL_UI_PROBE=PASS",
            "OPENAI_TUNNEL_UI_AUTHENTICATED=",
            "OPENAI_TUNNEL_UI_MANAGE_AVAILABLE=",
            "OPENAI_TUNNEL_UI_ACCESS_REQUIRED=",
            "OPENAI_TUNNEL_UI_EXISTING_COUNT=",
        ):
            self.assertIn(marker, text)

    def test_bootstrap_surfaces_do_not_discard_failures(self):
        paths = [
            ROOT / "scripts" / "setup-remote-control-access.sh",
            ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml",
        ]
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertNotIn("|| true", text, f"{path} must handle failures explicitly")
            self.assertNotIn("set +e", text, f"{path} must keep shell fail-fast enabled")


if __name__ == "__main__":
    unittest.main()
