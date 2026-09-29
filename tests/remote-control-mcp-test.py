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

    def test_oci_bastion_stage7_storage_recovery_is_guarded(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for action in (
            "action=backend-storage-scan",
            "action=backend-storage-cleanup-safe",
        ):
            self.assertIn(action, text)

        scan_name = "- name: Scan backend storage for Stage 7 recovery"
        cleanup_name = "- name: Safely recover backend storage for Stage 7"
        self.assertEqual(text.count(scan_name), 1)
        self.assertEqual(text.count(cleanup_name), 1)

        start = text.index(cleanup_name)
        end = text.index("- name:", start + len(cleanup_name))
        cleanup = text[start:end]
        for needle in (
            "path_in_use()",
            "SKIPPED_ACTIVE=",
            "BACKEND_STORAGE_CLEANUP_BEGIN",
            "BACKEND_STORAGE_CLEANUP_END",
            "/home/ubuntu/.cache/ms-playwright",
            "/home/ubuntu/.npm/_cacache",
            "/home/ubuntu/.npm/_npx",
            "shopvivaliz-claude-oci-*",
        ):
            self.assertIn(needle, cleanup)
        self.assertNotIn("docker system prune", cleanup)
        self.assertNotIn("/home/ubuntu/shopvivaliz-deploy/releases", cleanup)
        self.assertNotIn("/home/ubuntu/shopvivaliz-deploy/current", cleanup)

    def test_oci_bastion_stage7_storage_recovery_shell_is_bounded_and_nounset_safe(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        cleanup_name = "- name: Safely recover backend storage for Stage 7"
        scan_name = "- name: Scan backend storage for Stage 7 recovery"
        cleanup_start = text.index(cleanup_name)
        cleanup_end = text.index("- name:", cleanup_start + len(cleanup_name))
        cleanup = text[cleanup_start:cleanup_end]
        scan_start = text.index(scan_name)
        scan_end = text.index("- name:", scan_start + len(scan_name))
        scan = text[scan_start:scan_end]

        self.assertIn("read -r filesystem blocks used free_kb capacity mountpoint", cleanup)
        self.assertNotIn('awk "NR==2 {print', cleanup)
        self.assertIn("BACKEND_STORAGE_SCAN_WARN=home_du_partial", scan)
        self.assertIn("BACKEND_STORAGE_SCAN_WARN=var_du_partial", scan)
        self.assertIn("timeout 120s", scan)

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
        self.assertIn("process.exit(process.exitCode || 0)", text)
        self.assertNotIn("browser.close(", text)

        auth_script = ROOT / "scripts" / "openai-secure-mcp-platform-auth.mjs"
        self.assertTrue(auth_script.exists(), "Secure MCP Platform auth helper missing")
        auth_text = auth_script.read_text(encoding="utf-8")
        for needle in (
            "https://platform.openai.com/settings/organization/tunnels",
            "Continue with Google",
            "OPENAI_PLATFORM_AUTH_RESULT=",
            "OPENAI_PLATFORM_AUTHENTICATED=",
            "OPENAI_PLATFORM_TUNNEL_MANAGE_AVAILABLE=",
            "OPENAI_PLATFORM_AUTH_CHALLENGE_REQUIRED=",
            "CLOUD_CLIENT_COMPATIBILITY_REQUIRED=",
            "CLAUDE_CLOUD_ACCESS_REQUIRED=",
        ):
            self.assertIn(needle, auth_text)
        self.assertNotIn("console.log(bodyText", auth_text)
        self.assertNotIn("console.log(currentUrl", auth_text)

        for marker in (
            "OPENAI_PLATFORM_AUTH_STAGE=",
            "OPENAI_PLATFORM_ACCOUNT_CHOOSER_PRESENT=",
            "OPENAI_PLATFORM_AUTH_RETURNED_TO_PLATFORM=",
        ):
            self.assertIn(marker, auth_text)

        auth_flow = ROOT / ".github" / "workflows" / "secure-mcp-platform-auth.yml"
        self.assertTrue(auth_flow.exists(), "Secure MCP Platform auth workflow missing")
        auth_flow_text = auth_flow.read_text(encoding="utf-8")
        for needle in (
            "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]",
            "github.event.issue.title == '[secure-mcp-platform-auth]'",
            "github.event.issue.user.login == 'fredmourao-ai'",
            "github.event.issue.body == 'action=auth'",
            "timeout 120s node scripts/openai-secure-mcp-platform-auth.mjs",
            "OPENAI_PLATFORM_AUTH_RESULT=PASS",
        ):
            self.assertIn(needle, auth_flow_text)

        direct = ROOT / ".github" / "workflows" / "secure-mcp-platform-ui-probe.yml"
        self.assertTrue(direct.exists(), "direct backend Platform UI probe workflow missing")
        direct_text = direct.read_text(encoding="utf-8")
        for needle in (
            "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]",
            "github.event.issue.title == '[secure-mcp-platform-ui-probe]'",
            "github.event.issue.user.login == 'fredmourao-ai'",
            "github.event.issue.body == 'action=probe'",
            "timeout 90s node scripts/openai-secure-mcp-platform-probe.mjs",
            "OPENAI_TUNNEL_UI_PROBE=PASS",
        ):
            self.assertIn(needle, direct_text)

    def test_remote_access_can_probe_platform_tunnel_on_backend(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        for needle in (
            "secure_mcp_platform_ui_probe",
            "scripts/openai-secure-mcp-platform-probe.mjs",
            "ubuntu@10.0.1.38",
            "timeout 90s node",
            "OPENAI_TUNNEL_UI_PROBE=PASS",
            "SECURE_MCP_PLATFORM_REMOTE_PROBE=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertIn('action == "secure_mcp_platform_ui_probe"', workflow)
        self.assertIn('target != "always-free-arm-1787907847-26"', workflow)


    def test_remote_access_supports_remaining_cloud_mcp_gates(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        for action in (
            "secure_mcp_platform_auth",
            "secure_mcp_cloudflare_access_probe",
        ):
            self.assertIn(action, workflow)
            self.assertIn(f'action == "{action}"', workflow)
        for needle in (
            "scripts/openai-secure-mcp-platform-auth.mjs",
            "OPENAI_PLATFORM_AUTH_RESULT=PASS",
            "SECURE_MCP_PLATFORM_REMOTE_AUTH=PASS",
            "CLOUDFLARE_ACCESS_APPS_READ=",
            "CLOUDFLARE_ACCESS_SERVICE_TOKENS_READ=",
            "CLOUDFLARE_ACCESS_MANAGED_OAUTH_CAPABLE=",
            "CLOUDFLARE_ACCESS_PROBE=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertGreaterEqual(workflow.count('target != "always-free-arm-1787907847-26"'), 3)


    def test_remote_access_can_probe_cloudflare_access_ui_on_backend(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        script = ROOT / "scripts" / "cloudflare-access-ui-probe.mjs"
        self.assertTrue(script.exists(), "Cloudflare Access UI probe script missing")
        script_text = script.read_text(encoding="utf-8")
        for marker in (
            "CLOUDFLARE_UI_AUTHENTICATED=",
            "CLOUDFLARE_ZERO_TRUST_AVAILABLE=",
            "CLOUDFLARE_ACCESS_MANAGE_AVAILABLE=",
            "CLOUDFLARE_UI_AUTH_CHALLENGE_REQUIRED=",
            "CLOUDFLARE_UI_PROBE=PASS",
        ):
            self.assertIn(marker, script_text)
        self.assertIn("https://dash.cloudflare.com/", script_text)
        self.assertIn("https://one.dash.cloudflare.com/", script_text)
        for needle in (
            "secure_mcp_cloudflare_ui_probe",
            "scripts/cloudflare-access-ui-probe.mjs",
            "CLOUDFLARE_UI_PROBE=PASS",
            "SECURE_MCP_CLOUDFLARE_UI_REMOTE_PROBE=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertIn('action == "secure_mcp_cloudflare_ui_probe"', workflow)
        self.assertIn('target != "always-free-arm-1787907847-26"', workflow)

    def test_remote_access_can_probe_claude_cloud_remote_control(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        script = ROOT / "scripts" / "claude-remote-control-probe.sh"
        self.assertTrue(script.exists(), "Claude Remote Control probe script missing")
        script_text = script.read_text(encoding="utf-8")
        for marker in (
            "CLAUDE_REMOTE_CONTROL_PROBE=PASS",
            "CLAUDE_PRESENT=",
            "CLAUDE_AUTH_LOGGED_IN=",
            "CLAUDE_REMOTE_CONTROL_COMMAND_AVAILABLE=",
            "CLAUDE_REMOTE_CONTROL_ENV_COMPATIBLE=",
            "CLAUDE_VERSION=",
        ):
            self.assertIn(marker, script_text)
        for needle in (
            "claude_remote_control_probe",
            "scripts/claude-remote-control-probe.sh",
            "SECURE_CLAUDE_REMOTE_CONTROL_PROBE=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertIn('action == "claude_remote_control_probe"', workflow)
        self.assertIn('target != "always-free-arm-1787907847-26"', workflow)

    def test_codex_remote_control_uses_fresh_run_scoped_worktree(self):
        workflow = (ROOT / ".github" / "workflows" / "codex-remote-control-mcp-one-shot.yml").read_text(encoding="utf-8")
        self.assertIn('branch="codex/remote-control-stage7-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"', workflow)
        self.assertIn('worktree="$HOME/worktrees/site-shopvivaliz/remote-control-stage7-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"', workflow)
        self.assertIn('git -C "$src" worktree add -b "$branch" "$worktree" origin/main', workflow)
        self.assertNotIn('branch="feat/remote-control-cloud-clients-20260928"', workflow)
        self.assertNotIn('merge --ff-only origin/main', workflow)
        self.assertNotIn('merge --no-edit origin/main', workflow)
        self.assertNotIn('reset --hard origin/main', workflow)
        self.assertNotIn('rebase origin/main', workflow)

    def test_claude_workspace_trust_bootstrap_is_tty_bounded_and_allowlisted(self):
        helper = ROOT / "scripts" / "claude_workspace_trust_bootstrap.py"
        self.assertTrue(helper.exists(), "Claude workspace trust PTY helper missing")

        spec = importlib.util.spec_from_file_location("claude_workspace_trust_bootstrap", helper)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(module)

        current_278 = (
            "Accessing workspace:\n/tmp/shopvivaliz\n"
            "Quick safety check: Is this a project you created or one you trust?\n"
            "❯ No, exit\n"
            "  Yes, I trust this folder\n"
            "Enter to confirm · Esc to cancel"
        )
        newer_dialog = (
            "Do you trust the files in this folder?\n"
            "❯ No, exit\n"
            "  Yes, I trust this folder"
        )
        already_selected_yes = (
            "Quick safety check: Is this a project you created or one you trust?\n"
            "  No, exit\n"
            "❯ Yes, I trust this folder"
        )

        self.assertTrue(module.trust_prompt_visible(current_278))
        self.assertTrue(module.trust_prompt_visible(newer_dialog))
        self.assertEqual(module.trust_acceptance_sequence(current_278), b"\x1b[B\r")
        self.assertEqual(module.trust_acceptance_sequence(newer_dialog), b"\x1b[B\r")
        self.assertEqual(module.trust_acceptance_sequence(already_selected_yes), b"\r")
        self.assertEqual(module.remote_control_acceptance_sequence("Enable Remote Control? (y/n)"), b"y\r")
        self.assertIsNone(module.remote_control_acceptance_sequence("Enable Remote Control? (yes/no)"))
        self.assertEqual(module.documented_server_trust_sequence("Trust /tmp/shopvivaliz? [y/N]", "/tmp/shopvivaliz"), b"y\r")
        self.assertTrue(module.server_startup_visible("https://claude.ai/code/example-session"))
        self.assertIsNone(module.trust_acceptance_sequence("Enable Remote Control? (y/n)"))
        self.assertIsNone(module.trust_acceptance_sequence("Do you want to allow this tool?"))
        self.assertFalse(module.unexpected_prompt_visible("Enable Remote Control? (y/n)", "/tmp/shopvivaliz"))
        self.assertTrue(module.unexpected_prompt_visible("Do you want to allow this tool?", "/tmp/shopvivaliz"))

        helper_text = helper.read_text(encoding="utf-8")
        setup_text = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        self.assertIn("pty.openpty()", helper_text)
        self.assertIn("TIOCSWINSZ", helper_text)
        self.assertIn('env["TERM"] = "xterm-256color"', helper_text)
        self.assertNotIn('os.write(master_fd, b"1\\r")', helper_text)
        self.assertNotIn('[claude_bin, "--remote-control"]', helper_text)
        self.assertIn('"remote-control"', helper_text)
        self.assertIn("ShopVivaliz-Trust-Bootstrap", helper_text)
        self.assertIn("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS", helper_text)
        self.assertNotIn("hasTrustDialogAccepted", helper_text)
        self.assertNotIn("hasTrustDialogAccepted", setup_text)
        self.assertIn("claude_workspace_trust_bootstrap.py", setup_text)

    def test_claude_remote_control_service_keeps_mcp_bearer_off_claude_config(self):
        setup = ROOT / "scripts" / "setup-claude-remote-control.sh"
        bridge = ROOT / "scripts" / "claude-remote-control-mcp-stdio.py"
        unit = ROOT / "deploy" / "systemd" / "shopvivaliz-claude-remote-control.service"
        for path in (setup, bridge, unit):
            self.assertTrue(path.exists(), f"missing Claude Remote Control file: {path}")
        setup_text = setup.read_text(encoding="utf-8")
        bridge_text = bridge.read_text(encoding="utf-8")
        unit_text = unit.read_text(encoding="utf-8")
        self.assertIn('"type":"stdio"', setup_text)
        self.assertIn('/usr/local/sbin/shopvivaliz-claude-mcp-stdio', setup_text)
        self.assertIn('run_in_workspace_as_claude(){', setup_text)
        self.assertIn('run_in_workspace_as_claude timeout 18s "$CLAUDE_BIN" remote-control', setup_text)
        self.assertIn('/var/lib/shopvivaliz-remote-control/mcp-token', bridge_text)
        self.assertIn('http://127.0.0.1:5580/mcp', bridge_text)
        self.assertNotIn('mcp-token', unit_text)
        self.assertNotIn('ANTHROPIC_API_KEY', unit_text)
        self.assertIn('claude remote-control', unit_text)
        self.assertIn('--spawn worktree', unit_text)
        self.assertIn('--no-create-session-in-dir', unit_text)
        self.assertIn('StandardOutput=null', unit_text)

    def test_remote_access_can_install_and_verify_claude_remote_control(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        for action in ("claude_remote_control_install", "claude_remote_control_status"):
            self.assertIn(action, workflow)
            self.assertIn(f'action == "{action}"', workflow)
        for needle in (
            "scripts/setup-claude-remote-control.sh",
            "scripts/claude-remote-control-mcp-stdio.py",
            "scripts/claude_workspace_trust_bootstrap.py",
            "deploy/systemd/shopvivaliz-claude-remote-control.service",
            "CLAUDE_REMOTE_CONTROL_INSTALL=PASS",
            "CLAUDE_REMOTE_CONTROL_STATUS=PASS",
        ):
            self.assertIn(needle, workflow)

    def test_claude_remote_control_auth_json_is_not_read_as_unprivileged_user(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        self.assertNotIn('run_as_claude python3 - "$tmp"', setup)
        self.assertIn('python3 - "$tmp"', setup)

    def test_claude_bridge_verification_classifies_failures_safely(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        for marker in (
            "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=adapter",
            "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=json",
            "CLAUDE_PRIVATE_MCP_BRIDGE=FAIL class=tools",
            "CLAUDE_PRIVATE_MCP_BRIDGE=PASS",
        ):
            self.assertIn(marker, setup)
        self.assertIn("bridge_rc=", setup)
        self.assertNotIn("echo \"$out\"", setup)
        self.assertNotIn("printf '%s\\n' \"$out\"", setup)

    def test_claude_bridge_requires_only_real_controller_tools(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        self.assertNotIn('"task_result"', setup)
        for tool in ("hosts_list", "host_health", "task_submit", "task_status"):
            self.assertIn(f'"{tool}"', setup)
            self.assertIn(tool, {item["name"] for item in m.tool_specs()})

    def test_remote_access_can_diagnose_chatgpt_continuity_runtime(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        for needle in (
            "chatgpt_continuity_diagnostic",
            "CHATGPT_CONTINUITY_AGENT_ACTIVE=",
            "CHATGPT_CONTINUITY_RUNNING_TASKS=",
            "CHATGPT_CONTINUITY_PENDING_REQUESTS=",
            "CHATGPT_CONTINUITY_QUEUE_CERTIFIER_AVAILABLE=",
            "CHATGPT_CONTINUITY_QUEUE_CERTIFIED=",
            "CHATGPT_CONTINUITY_QUEUE_RAW_ROWS=",
            "CHATGPT_CONTINUITY_QUEUE_ACTIONABLE_ROWS=",
            "CHATGPT_CONTINUITY_QUEUE_NONACTIONABLE_ROWS=",
            "CHATGPT_CONTINUITY_QUEUE_ARCHIVE_PRESENT=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_TOTAL=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_PENDING=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_CLAIMED=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_ACTIVE=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_RESOLVED=",
            "CHATGPT_CONTINUITY_LATEST_NUDGE_STATUS=",
            "CHATGPT_CONTINUITY_BACKEND_WORKER_ACTIVE=",
            "CHATGPT_CONTINUITY_CDP_REACHABLE=",
            "CHATGPT_CONTINUITY_BRIDGE_HEARTBEAT=",
            "CHATGPT_CONTINUITY_LATEST_CONVERSATION_DISCOVERABLE=",
            "CHATGPT_CONTINUITY_DIAGNOSTIC=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertIn('action == "chatgpt_continuity_diagnostic"', workflow)
        self.assertIn('target != "always-free-arm-1787907847-26"', workflow)

    def test_claude_remote_control_consent_failures_are_classified(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        for marker in (
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=repo",
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=trust",
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=tty",
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=flag",
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=auth",
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=policy",
            "CLAUDE_REMOTE_CONTROL_CONSENT=FAIL class=other",
            "CLAUDE_REMOTE_CONTROL_CONSENT=PASS",
        ):
            self.assertIn(marker, setup)
        self.assertNotIn('cat "$out"', setup)

    def test_remote_access_can_repair_chatgpt_continuity_runtime(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        for needle in (
            "chatgpt_continuity_repair",
            "scripts/install-chatgpt-continuity-backend-bridge.sh",
            "scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs",
            "CHATGPT_CONTINUITY_TOKEN_SYNC=PASS",
            "CHATGPT_CONTINUITY_BACKEND_SERVICE=PASS",
            "CHATGPT_CONTINUITY_BRIDGE_HEARTBEAT=PASS",
            "CHATGPT_CONTINUITY_REPAIR=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertIn('action == "chatgpt_continuity_repair"', workflow)
        self.assertIn('target != "always-free-arm-1787907847-26"', workflow)
        repair_block = workflow.split("            chatgpt_continuity_repair)", 1)[1].split("              ;;", 1)[0]
        self.assertNotIn('cat "$token_file"', repair_block)
        self.assertIn('dd if="$token_file" status=none', repair_block)

    def test_chatgpt_continuity_repair_discovers_authenticated_private_bridge_route(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        for needle in (
            "CHATGPT_CONTINUITY_BRIDGE_ROUTE=",
            "http://10.0.1.112/api/chatgpt-continuity/bridge.php",
            "http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php",
            "jq -e '.status == \"OK\"'",
        ):
            self.assertIn(needle, workflow)
        repair = workflow.split("chatgpt_continuity_repair)", 1)[1].split("chatgpt_continuity_diagnostic)", 1)[0]
        self.assertIn("bridge_endpoint=''", repair)
        self.assertIn("for candidate in", repair)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_ENDPOINT='$bridge_endpoint'", repair)

    def test_chatgpt_continuity_diagnostic_checks_user_service_scope(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("chatgpt_continuity_diagnostic)", 1)[1].split("secure_mcp_platform_ui_probe)", 1)[0]
        self.assertIn("systemctl --user is-active --quiet shopvivaliz-chatgpt-continuity.service", diagnostic)
        self.assertIn("XDG_RUNTIME_DIR=", diagnostic)
        self.assertIn("DBUS_SESSION_BUS_ADDRESS=", diagnostic)

    def test_chatgpt_continuity_diagnostic_uses_backend_bridge_route(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("chatgpt_continuity_diagnostic)", 1)[1].split("secure_mcp_platform_ui_probe)", 1)[0]
        self.assertIn("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", diagnostic)
        for route in (
            "http://10.0.1.112/api/chatgpt-continuity/bridge.php",
            "http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php",
            "https://shopvivaliz.com.br/api/chatgpt-continuity/bridge.php",
        ):
            self.assertIn(route, diagnostic)
        self.assertNotIn("http://127.0.0.1:8080/api/chatgpt-continuity/bridge.php", diagnostic)

    def test_chatgpt_continuity_diagnostic_surfaces_safe_latest_probe(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("chatgpt_continuity_diagnostic)", 1)[1].split("secure_mcp_platform_ui_probe)", 1)[0]
        self.assertIn("latestConversationProbe", diagnostic)
        self.assertIn("CHATGPT_CONTINUITY_LATEST_LIST_HTTP_STATUS=", diagnostic)
        self.assertIn("CHATGPT_CONTINUITY_LATEST_LIST_SOURCE=", diagnostic)
        self.assertNotIn("CHATGPT_CONTINUITY_LATEST_CONVERSATION_ID=", diagnostic)

        for marker in (
            "CHATGPT_CONTINUITY_LATEST_ITEM_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_ID_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_ID_VALID=",
            "CHATGPT_CONTINUITY_LATEST_UPDATE_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_UPDATE_VALID=",
            "CHATGPT_CONTINUITY_LATEST_ITEM_KEYS=",
        ):
            self.assertIn(marker, diagnostic)
        worker = (ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-continuity-bridge-worker.mjs").read_text(encoding="utf-8")
        self.assertIn("Object.keys(item)", worker)
        self.assertIn("replace(/[^A-Za-z0-9_]/g", worker)

    def test_oci_bastion_can_repair_chatgpt_continuity_when_a1_runner_is_unavailable(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for needle in (
            "action=chatgpt-continuity-repair",
            "ChatGPT continuity repair through OCI Bastion",
            "scripts/install-chatgpt-continuity-backend-bridge.sh",
            "scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs",
            "/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token",
            "CHATGPT_CONTINUITY_BASTION_REPAIR=PASS",
            "CHATGPT_CONTINUITY_BACKEND_SERVICE=PASS",
            "CHATGPT_CONTINUITY_BRIDGE_HEARTBEAT=PASS",
        ):
            self.assertIn(needle, workflow)
        repair = workflow.split("ChatGPT continuity repair through OCI Bastion", 1)[1].split("ChatGPT continuity diagnostic through Remote Control MCP", 1)[0]
        self.assertNotIn("echo $token", repair)
        self.assertNotIn("cat $token_file", repair)
        self.assertIn("dd if=\"$token_file\" status=none", repair)

    def test_oci_bastion_can_diagnose_chatgpt_continuity_via_mcp(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for needle in (
            "action=chatgpt-continuity-diagnostic",
            "ChatGPT continuity diagnostic through Remote Control MCP",
            "CHATGPT_CONTINUITY_AGENT_ACTIVE=",
            "CHATGPT_CONTINUITY_RUNNING_TASKS=",
            "CHATGPT_CONTINUITY_PENDING_REQUESTS=",
            "CHATGPT_CONTINUITY_LATEST_NUDGE_STATUS=",
            "CHATGPT_CONTINUITY_BACKEND_WORKER_ACTIVE=",
            "CHATGPT_CONTINUITY_CDP_REACHABLE=",
            "CHATGPT_CONTINUITY_LATEST_CONVERSATION_DISCOVERABLE=",
            "CHATGPT_CONTINUITY_BRIDGE_HEARTBEAT=",
            "CHATGPT_CONTINUITY_LATEST_ITEM_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_ID_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_ID_VALID=",
            "CHATGPT_CONTINUITY_LATEST_UPDATE_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_UPDATE_VALID=",
            "CHATGPT_CONTINUITY_LATEST_ITEM_KEYS=",
            "CHATGPT_CONTINUITY_FILTERED_HTTP_STATUS=",
            "CHATGPT_CONTINUITY_FILTERED_BODY_KIND=",
            "CHATGPT_CONTINUITY_FILTERED_ITEMS_COUNT=",
            "CHATGPT_CONTINUITY_FILTERED_CONVERSATIONS_COUNT=",
            "CHATGPT_CONTINUITY_FILTERED_DATA_KIND=",
            "CHATGPT_CONTINUITY_FILTERED_DATA_ITEMS_COUNT=",
            "CHATGPT_CONTINUITY_FILTERED_DATA_CONVERSATIONS_COUNT=",
            "CHATGPT_CONTINUITY_FALLBACK_HTTP_STATUS=",
            "CHATGPT_CONTINUITY_FALLBACK_BODY_KIND=",
            "CHATGPT_CONTINUITY_FALLBACK_ITEMS_COUNT=",
            "CHATGPT_CONTINUITY_FALLBACK_CONVERSATIONS_COUNT=",
            "CHATGPT_CONTINUITY_FALLBACK_DATA_KIND=",
            "CHATGPT_CONTINUITY_FALLBACK_DATA_ITEMS_COUNT=",
            "CHATGPT_CONTINUITY_FALLBACK_DATA_CONVERSATIONS_COUNT=",
            "CHATGPT_CONTINUITY_CURRENT_PATH_KIND=",
            "CHATGPT_CONTINUITY_CURRENT_CONVERSATION_FETCH_STATUS=",
            "CHATGPT_CONTINUITY_MCP_DIAGNOSTIC=PASS",
        ):
            self.assertIn(needle, workflow)
        self.assertIn("Object.keys(item)", workflow)
        self.assertIn("replace(/[^A-Za-z0-9_]/g", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_LATEST_CONVERSATION_ID=", workflow)
        self.assertIn("admin_command_run", workflow)
        self.assertIn("always-free-arm-1787907847-26", workflow)
        self.assertIn("shopvivaliz-free-a1", workflow)

    def test_oci_continuity_diagnostic_bounds_backend_browser_probes(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("- name: ChatGPT continuity diagnostic through Remote Control MCP", 1)[1].split("- name: Probe Secure MCP Tunnel prerequisites", 1)[0]
        self.assertGreaterEqual(
            diagnostic.count("timeout 10s sudo -u ubuntu node --input-type=module"),
            3,
            "every backend browser probe must have its own deadline inside the 45s MCP command budget",
        )
        for fallback in (
            "CHATGPT_CONTINUITY_FILTERED_HTTP_STATUS=0",
            "CHATGPT_CONTINUITY_SESSION_HTTP_STATUS=0",
            "CHATGPT_CONTINUITY_WORKER_LATEST_LIST_SOURCE=probe_failed",
        ):
            self.assertIn(fallback, diagnostic)

    def test_oci_continuity_diagnostic_uses_installed_worker_latest_probe(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for needle in (
            "latestConversationProbe",
            "normalizeLatestConversationMeta",
            "CHATGPT_CONTINUITY_WORKER_LATEST_LIST_HTTP_STATUS=",
            "CHATGPT_CONTINUITY_WORKER_LATEST_LIST_SOURCE=",
            "CHATGPT_CONTINUITY_WORKER_LATEST_META_VALID=",
        ):
            self.assertIn(needle, workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_WORKER_LATEST_CONVERSATION_ID=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_WORKER_ACCOUNT_ID=", workflow)

    def test_oci_continuity_diagnostic_surfaces_dispatcher_link(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for marker in (
            "CHATGPT_CONTINUITY_MATCHING_REQUESTS=",
            "CHATGPT_CONTINUITY_CURRENT_LEDGER_PRESENT=",
            "CHATGPT_CONTINUITY_TOKEN_AVAILABLE=",
            "CHATGPT_CONTINUITY_DISPATCHER_LOG_SEEN=",
        ):
            self.assertIn(marker, workflow)

    def test_oci_continuity_diagnostic_certifies_both_queues_without_payloads(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for marker in (
            "CHATGPT_CONTINUITY_QUEUE_CERTIFIER_AVAILABLE=",
            "CHATGPT_CONTINUITY_QUEUE_CERTIFIED=",
            "CHATGPT_CONTINUITY_QUEUE_RAW_ROWS=",
            "CHATGPT_CONTINUITY_QUEUE_ACTIONABLE_ROWS=",
            "CHATGPT_CONTINUITY_QUEUE_NONACTIONABLE_ROWS=",
            "CHATGPT_CONTINUITY_QUEUE_ARCHIVE_PRESENT=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_TOTAL=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_PENDING=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_CLAIMED=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_ACTIVE=",
            "CHATGPT_CONTINUITY_BRIDGE_QUEUE_RESOLVED=",
            "task_resume_queue.certify_queue",
        ):
            self.assertIn(marker, workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_QUEUE_ROW=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_BRIDGE_QUEUE_TASK_ID=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_BRIDGE_QUEUE_DETAIL=", workflow)

    def test_oci_continuity_diagnostic_surfaces_canonical_g2_status(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_CONTINUITY_CANONICAL_TASK_STATUS=", workflow)
        self.assertIn("chatgpt-freeze-root-cause-20260928-g2.json", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_CANONICAL_TASK_JSON=", workflow)

    def test_oci_continuity_diagnostic_surfaces_latest_generation_health_safely(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for marker in (
            "CHATGPT_CONTINUITY_LATEST_GENERATION_NUMBER=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_STATUS=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_READABLE=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_OWNER_MATCHES_RUNTIME=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_MODE_0600=",
        ):
            self.assertIn(marker, workflow)
        self.assertIn("chatgpt-freeze-root-cause-20260928-g*.json", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_LATEST_GENERATION_TASK_ID=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_LATEST_GENERATION_JSON=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_LATEST_GENERATION_EVIDENCE=", workflow)

    def test_oci_continuity_diagnostic_surfaces_g2_terminal_history_safely(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for marker in (
            "CHATGPT_CONTINUITY_CANONICAL_TASK_UPDATED_AT=",
            "CHATGPT_CONTINUITY_CANONICAL_TASK_COMPLETED_AT=",
            "CHATGPT_CONTINUITY_CANONICAL_TASK_EVIDENCE_COUNT=",
            "CHATGPT_CONTINUITY_CANONICAL_TASK_VERIFICATION_PRESENT=",
            "CHATGPT_CONTINUITY_CANONICAL_TASK_HISTORY_EVENTS=",
        ):
            self.assertIn(marker, workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_CANONICAL_TASK_EVIDENCE=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_CANONICAL_TASK_VERIFICATION=", workflow)
        self.assertIn("import hashlib, json, pathlib, re, sys", workflow)


    def test_oci_bastion_can_ensure_next_chatgpt_freeze_generation(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for needle in (
            "action=chatgpt-continuity-successor",
            "scripts/ensure-chatgpt-freeze-successor.py",
            "CHATGPT_CONTINUITY_SUCCESSOR=PASS",
            "--base-id chatgpt-freeze-root-cause-20260928",
            "admin_command_run",
            "shopvivaliz-free-a1",
        ):
            self.assertIn(needle, workflow)

    def test_oci_bastion_can_validate_claude_stage7_via_remote_control_mcp(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        for action in (
            "action=claude-remote-control-install",
            "action=claude-remote-control-status",
        ):
            self.assertIn(action, workflow)
        self.assertIn("scripts/oci-mcp-stage7-action.py", workflow)
        self.assertIn("claude-install", workflow)
        self.assertIn("claude-status", workflow)
        self.assertIn("admin_command_run", helper)
        self.assertIn('BACKEND = "always-free-arm-1787907847-26"', helper)
        self.assertIn("CLAUDE_REMOTE_CONTROL_INSTALL=PASS", helper)
        self.assertIn("CLAUDE_PRIVATE_MCP_BRIDGE=PASS", helper)
        self.assertIn("CLAUDE_REMOTE_CONTROL_STATUS=PASS", helper)
        install_start = workflow.index("- name: Install Claude Remote Control through Remote Control MCP")
        status_start = workflow.index("- name: Check Claude Remote Control status through Remote Control MCP")
        self.assertNotIn("mcp-token", workflow[install_start:status_start])

    def test_stage7_claude_staging_avoids_ephemeral_backend_tmp(self):
        oci = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        remote = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        stable_root = "/home/ubuntu/.local/state/shopvivaliz/claude-stage7"
        self.assertIn(stable_root, oci)
        self.assertIn(stable_root, remote)
        self.assertIn(stable_root, helper)
        self.assertNotIn('remote_dir="/tmp/shopvivaliz-claude-oci-', oci)
        self.assertNotIn('remote_dir="/tmp/shopvivaliz-claude-remote-control-', remote)

    def test_oci_stage7_claude_failure_diagnostics_are_sanitized(self):
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        self.assertIn("def classify_remote_failure(", helper)
        self.assertIn("CLAUDE_OCI_FAILURE_CLASS=", helper)
        for label in (
            "storage",
            "trust",
            "auth",
            "timeout",
            "permission",
            "missing",
            "service",
            "mcp",
            "remote_command",
        ):
            self.assertIn(f'"{label}"', helper)
        self.assertNotIn('print(stderr)', helper)
        self.assertNotIn('print(result.get("stderr"', helper)
        self.assertIn("CLAUDE_OCI_RESULT_EXIT_CODE=", helper)
        self.assertIn("CLAUDE_OCI_STDOUT_BYTES=", helper)
        self.assertIn("CLAUDE_OCI_STDERR_BYTES=", helper)
        self.assertIn("CLAUDE_OCI_ERROR_PRESENT=", helper)
        self.assertIn("CLAUDE_OCI_SAFE_MARKER_COUNT=", helper)
        self.assertIn("CLAUDE_OCI_STAGE_PREPARE=PASS", helper)
        self.assertIn("CLAUDE_OCI_STAGE_PREPARE=FAIL class=permissions", helper)
        self.assertIn("CLAUDE_OCI_STAGE_INSTALLER=START", helper)
        self.assertIn("CLAUDE_OCI_STAGE_SETUP_FILE=PASS", helper)
        self.assertIn("CLAUDE_OCI_STAGE_SETUP_CONTRACT=PASS", helper)
        self.assertIn("CLAUDE_OCI_STAGE_SETUP_SYNTAX=PASS", helper)
        self.assertIn("bash -n", helper)
        self.assertIn("CLAUDE_OCI_SETUP_OUTPUT_PRESENT=", helper)
        self.assertIn("CLAUDE_OCI_SETUP_FAILURE_CLASS=", helper)
        for setup_label in (
            "shell_startup",
            "storage",
            "permission",
            "missing",
            "resource",
            "setup_runtime",
        ):
            self.assertIn(setup_label, helper)
        self.assertNotIn("CLAUDE_OCI_SETUP_OUTPUT=", helper)
        self.assertIn('str(result.get("error") or "")', helper)
        self.assertNotIn("print(error)", helper)
        self.assertIn("CLAUDE_OCI_BASH_ENV_PRESENT=", helper)
        self.assertIn("CLAUDE_OCI_BASH_STARTUP=", helper)
        self.assertIn("CLAUDE_OCI_BASH_STARTUP_CLEAN=", helper)
        self.assertIn("env -u BASH_ENV bash -c", helper)
        self.assertNotIn("CLAUDE_OCI_BASH_ENV_VALUE=", helper)
        self.assertNotIn("CLAUDE_OCI_BASH_STARTUP_OUTPUT=", helper)

    def test_claude_setup_emits_sanitized_phase_markers_before_each_install_step(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        for phase in (
            "eligibility",
            "bridge_install",
            "workspace",
            "mcp_config",
            "bridge_verify",
            "consent",
            "service",
        ):
            self.assertIn(f'CLAUDE_REMOTE_CONTROL_PHASE={phase}', setup)


    def test_claude_setup_emits_sanitized_eligibility_subphases_and_guards_tmpfile(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        for phase in (
            "binary",
            "auth_status",
            "logged_in",
            "remote_control_help",
        ):
            self.assertIn(f'CLAUDE_REMOTE_CONTROL_ELIGIBILITY={phase}', setup)
        self.assertIn('tmp="$(mktemp)" || die claude_auth_tmpfile_failed 33', setup)


    def test_claude_setup_classifies_auth_json_state_without_set_e_silence(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        self.assertIn("claude_not_logged_in", setup)
        self.assertIn("claude_auth_status_invalid", setup)
        self.assertIn("isinstance(data, dict)", setup)
        self.assertIn('"claude_not_logged_in"', helper)
        self.assertIn('"claude_auth_status_invalid"', helper)

    def test_oci_bastion_reads_canonical_g2_freeze_state_via_remote_control_mcp(self):

        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        self.assertIn("action=chatgpt-freeze-task-state", workflow)
        self.assertIn("freeze-state", workflow)
        self.assertIn("admin_command_run", helper)
        self.assertIn('SITE = "shopvivaliz-free-a1"', helper)
        self.assertIn("chatgpt-freeze-root-cause-20260928-g2", helper)
        self.assertIn("CHATGPT_FREEZE_CANONICAL_STATE=", helper)
        self.assertIn("TASK_TERMINAL_GATE=", helper)
        self.assertIn("sudo -u ubuntu -H python3", helper)
        self.assertNotIn("glob('chatgpt-freeze-root-cause-20260928-g*.json')", helper)
        self.assertNotIn("cat /home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/", helper)


    def test_oci_stage7_uses_real_bash_array_expansion(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        stage7 = workflow.split("Install Claude Remote Control through Remote Control MCP", 1)[1].split("ChatGPT continuity diagnostic through Remote Control MCP", 1)[0]
        self.assertNotIn(r'"\${BACKEND_SSH[@]}"', stage7)
        self.assertNotIn(r'"\${BACKEND_SCP[@]}"', stage7)
        self.assertIn('"${BACKEND_SSH[@]}"', stage7)
        self.assertIn('"${BACKEND_SCP[@]}"', stage7)
    def test_oci_continuity_diagnostic_probes_team_account_scope_without_leaking_credentials(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for marker in (
            "CHATGPT_CONTINUITY_SESSION_HTTP_STATUS=",
            "CHATGPT_CONTINUITY_SESSION_ACCESS_TOKEN_PRESENT=",
            "CHATGPT_CONTINUITY_SESSION_ACCOUNT_ID_PRESENT=",
            "CHATGPT_CONTINUITY_ACCOUNT_ID_SOURCE=",
            "CHATGPT_CONTINUITY_ACCOUNT_SCOPED_HTTP_STATUS=",
            "CHATGPT_CONTINUITY_ACCOUNT_SCOPED_ITEMS_COUNT=",
            "CHATGPT_CONTINUITY_ACCOUNT_SCOPED_CURRENT_FETCH_STATUS=",
        ):
            self.assertIn(marker, workflow)
        self.assertIn("/api/auth/session", workflow)
        self.assertIn("ChatGPT-Account-ID", workflow)
        self.assertIn("Authorization", workflow)
        self.assertIn("session?.account?.id", workflow)
        self.assertNotIn("https://api.openai.com/auth", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_SESSION_ACCESS_TOKEN=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_SESSION_ACCOUNT_ID=", workflow)
        self.assertNotIn("CHATGPT_CONTINUITY_ACCOUNT_SCOPED_CONVERSATION_ID=", workflow)

    def test_remote_control_ci_watches_oci_continuity_workflow(self):
        ci = (ROOT / ".github" / "workflows" / "remote-control-mcp-ci.yml").read_text(encoding="utf-8")
        watched = "- '.github/workflows/oci-bastion-private-access-bootstrap.yml'"
        self.assertGreaterEqual(ci.count(watched), 2)

    def test_remote_control_ci_watches_and_compiles_claude_trust_helper(self):
        ci = (ROOT / ".github" / "workflows" / "remote-control-mcp-ci.yml").read_text(encoding="utf-8")
        watched = "- 'scripts/claude_workspace_trust_bootstrap.py'"
        self.assertGreaterEqual(ci.count(watched), 2)
        self.assertIn("scripts/claude_workspace_trust_bootstrap.py", ci.split("python3 -m py_compile", 1)[1])
        self.assertGreaterEqual(ci.count("- 'scripts/oci-mcp-stage7-action.py'"), 2)
        self.assertIn("scripts/oci-mcp-stage7-action.py", ci.split("python3 -m py_compile", 1)[1])

    def test_bootstrap_surfaces_do_not_discard_failures(self):
        paths = [
            ROOT / "scripts" / "setup-remote-control-access.sh",
            ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml",
        ]
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertNotIn("|| true", text, f"{path} must handle failures explicitly")
            self.assertNotIn("set +e", text, f"{path} must keep shell fail-fast enabled")


    def test_claude_trust_bootstrap_uses_production_server_mode(self):
        helper = (ROOT / "scripts" / "claude_workspace_trust_bootstrap.py").read_text(encoding="utf-8")
        self.assertIn('"remote-control"', helper)
        self.assertIn("ShopVivaliz-Trust-Bootstrap", helper)
        self.assertIn("Trust ", helper)
        self.assertIn("[y/N]", helper)
        self.assertIn("Enable Remote Control?", helper)
        self.assertNotIn('[claude_bin, "--remote-control"]', helper)

    def test_claude_remote_control_unsets_feature_flag_blockers(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-claude-remote-control.service").read_text(encoding="utf-8")
        for name in (
            "CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC",
            "DISABLE_GROWTHBOOK",
            "DISABLE_TELEMETRY",
            "DO_NOT_TRACK",
        ):
            self.assertIn(f"-u {name}", setup)
            self.assertIn(name, unit)


    def test_claude_trust_bootstrap_handles_workspace_not_trusted_with_plain_cli(self):
        helper = (ROOT / "scripts" / "claude_workspace_trust_bootstrap.py").read_text(encoding="utf-8")
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        self.assertIn("workspace_not_trusted_visible", helper)
        self.assertIn("bootstrap_plain_workspace_trust", helper)
        self.assertIn("[claude_bin]", helper)
        self.assertIn("run_server_mode", helper)
        self.assertIn("server_startup_visible", helper)
        self.assertIn('systemctl stop "$SERVICE"', setup)
        self.assertNotIn('systemctl stop "$SERVICE" || true', setup)
        self.assertIn('timeout 90s python3 "$TRUST_HELPER_SOURCE" "$CLAUDE_BIN"', setup)

    def test_claude_trust_helper_bootstraps_plain_cli_before_server_mode(self):
        helper = (ROOT / "scripts" / "claude_workspace_trust_bootstrap.py").read_text(encoding="utf-8")
        main_body = helper.split("def main(argv: list[str]) -> int:", 1)[1]
        plain_call = "plain = bootstrap_plain_workspace_trust(claude_bin, workspace)"
        server_call = "server = run_server_mode(claude_bin, workspace)"
        self.assertIn(plain_call, main_body)
        self.assertIn(server_call, main_body)
        self.assertLess(main_body.index(plain_call), main_body.index(server_call))
        self.assertIn('"plain_prompt_missing"', main_body)
        self.assertIn('"plain_trust_persisted"', main_body)
        self.assertIn('"trust_not_persisted"', main_body)

    def test_claude_workspace_not_trusted_classifier_is_sanitized(self):
        helper_path = ROOT / "scripts" / "claude_workspace_trust_bootstrap.py"
        spec = importlib.util.spec_from_file_location("claude_workspace_trust_bootstrap_v2", helper_path)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(module)
        self.assertTrue(module.workspace_not_trusted_visible(
            "Error: Workspace not trusted. Please run claude in /tmp/example first to review and accept the workspace trust dialog."
        ))
        self.assertFalse(module.workspace_not_trusted_visible("Enable Remote Control? (y/n)"))


if __name__ == "__main__":
    unittest.main()
