#!/usr/bin/env python3
import importlib.util
import json
import os
import sqlite3
import tempfile
import threading
import time
import unittest
from unittest import mock
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
        self._original_launch_task_service = m.launch_task_service
        self._original_systemd_unit_state = m.systemd_unit_state
        self._runner_threads = []

        def launch_in_test(task_id, _timeout):
            runner = threading.Thread(target=m.run_task_entrypoint, args=(task_id,), daemon=True)
            self._runner_threads.append(runner)
            runner.start()

        m.launch_task_service = launch_in_test
        m.systemd_unit_state = lambda _unit: "active"
        m.init_db()

    def tearDown(self):
        m.STOP_EVENT.set()
        for runner in self._runner_threads:
            runner.join(timeout=2)
        m.launch_task_service = self._original_launch_task_service
        m.systemd_unit_state = self._original_systemd_unit_state
        self.tmp.cleanup()

    def test_db_conn_closes_connection_after_context(self):
        with m.db_conn() as db:
            self.assertEqual(db.execute("SELECT 1").fetchone()[0], 1)
        with self.assertRaises(sqlite3.ProgrammingError):
            db.execute("SELECT 1")

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
            "admin_command_run", "task_submit", "task_status", "task_wait", "task_cancel", "audit_recent",
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

    def test_root_runtime_wraps_commands_in_transient_systemd_scope(self):
        original_geteuid = m.os.geteuid
        original_systemd_run = m.SYSTEMD_RUN
        try:
            m.os.geteuid = lambda: 0
            m.SYSTEMD_RUN = "/bin/true"
            wrapped = m.isolated_invocation(["bash", "-lc", "printf ok"])
        finally:
            m.os.geteuid = original_geteuid
            m.SYSTEMD_RUN = original_systemd_run
        self.assertIn("--scope", wrapped)
        self.assertIn("CPUWeight=50", wrapped)
        self.assertIn("IOWeight=50", wrapped)
        self.assertEqual(wrapped[-3:], ["bash", "-lc", "printf ok"])

    def test_inline_concurrency_limit_fails_fast(self):
        original_slots = m.INLINE_COMMAND_SLOTS
        m.INLINE_COMMAND_SLOTS = threading.BoundedSemaphore(1)
        self.assertTrue(m.INLINE_COMMAND_SLOTS.acquire(blocking=False))
        try:
            with self.assertRaisesRegex(RuntimeError, "controller_busy_retry_or_use_task_submit"):
                m.run_host_command("always-free-arm-1787907847-26", "printf never")
        finally:
            m.INLINE_COMMAND_SLOTS.release()
            m.INLINE_COMMAND_SLOTS = original_slots

    def test_inline_command_cancels_entire_process_group_when_client_disconnects(self):
        marker = Path(self.tmp.name) / "orphan-child-wrote"
        started = time.monotonic()

        def disconnected():
            return time.monotonic() - started >= 0.15

        command = f"(sleep 1; printf leaked > '{marker}') & wait"
        with self.assertRaises(m.ClientDisconnected):
            m.run_host_command(
                "always-free-arm-1787907847-26",
                command,
                timeout=10,
                cancel_check=disconnected,
            )

        self.assertLess(time.monotonic() - started, 2.0)
        time.sleep(1.1)
        self.assertFalse(marker.exists(), "disconnect must terminate descendants, not just the parent shell")

    def test_execute_tool_propagates_inline_disconnect_but_durable_submit_stays_persistent(self):
        with self.assertRaises(m.ClientDisconnected):
            m.execute_tool(
                "admin_command_run",
                {
                    "host": "always-free-arm-1787907847-26",
                    "command": "sleep 30",
                    "timeout": 30,
                },
                cancel_check=lambda: True,
            )

        durable = m.execute_tool(
            "task_submit",
            {
                "host": "always-free-arm-1787907847-26",
                "command": "printf durable",
                "timeout": 30,
            },
            cancel_check=lambda: True,
        )
        self.assertEqual(durable["state"], "queued")

    def test_task_submit_is_idempotent_with_request_id(self):
        first = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26", "command": "printf durable",
            "timeout": 30, "request_id": "same-request",
        })
        second = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26", "command": "printf durable",
            "timeout": 30, "request_id": "same-request",
        })
        self.assertEqual(first["task_id"], second["task_id"])

    def test_task_wait_returns_terminal_result(self):
        submitted = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26", "command": "printf waited", "timeout": 30,
        })
        m.STOP_EVENT.clear()
        worker = threading.Thread(target=m.task_worker, daemon=True)
        worker.start()
        try:
            result = m.execute_tool("task_wait", {"task_id": submitted["task_id"], "wait_seconds": 5})
        finally:
            m.STOP_EVENT.set(); worker.join(timeout=5)
        self.assertEqual(result["state"], "succeeded")
        self.assertIn("waited", result["stdout"])

    def test_admin_command_can_be_explicitly_durable(self):
        result = m.execute_tool("admin_command_run", {
            "host": "always-free-arm-1787907847-26", "command": "sleep 30",
            "timeout": 30, "durable": True, "request_id": "admin-durable-1",
        }, cancel_check=lambda: True)
        self.assertEqual(result["state"], "queued")
        self.assertTrue(result["durable"])

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

    def test_governance_sanitizes_parent_git_hook_context_for_nested_git_tests(self):
        governance = (ROOT / "scripts" / "repository-governance-validate.sh").read_text(encoding="utf-8")
        line = next(
            row for row in governance.splitlines()
            if "tests.test_background_gemini_runner" in row
        )
        for name in (
            "GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_PREFIX",
            "GIT_OBJECT_DIRECTORY", "GIT_ALTERNATE_OBJECT_DIRECTORIES",
        ):
            self.assertIn(f"-u {name}", line)

    def test_controller_bootstrap_generates_root_only_mcp_token(self):
        setup = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8")
        self.assertIn("openssl rand -hex 32", setup)
        self.assertIn("mcp-token", setup)
        self.assertIn('UNIT_SOURCE="${3:-}"', setup)
        self.assertIn('install -m 0644 -o root -g root "$UNIT_SOURCE" "/etc/systemd/system/$SERVICE"', setup)
        self.assertIn('systemctl enable "$SERVICE"', setup)
        self.assertIn('systemctl restart "$SERVICE"', setup)
        self.assertNotIn('systemctl enable --now "$SERVICE"', setup)
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

    def test_task_submit_deduplicates_active_identical_work(self):
        first = m.execute_tool("task_submit", {"host":"always-free-arm-1787907847-26","command":"sleep 1","timeout":30})
        second = m.execute_tool("task_submit", {"host":"always-free-arm-1787907847-26","command":"sleep 1","timeout":30})
        self.assertEqual(first["task_id"], second["task_id"])
        self.assertTrue(second["deduplicated"])

    def test_task_wait_is_bounded_and_returns_terminal_task(self):
        submitted = m.execute_tool("task_submit", {"host":"always-free-arm-1787907847-26","command":"printf waited","timeout":30})
        m.STOP_EVENT.clear()
        worker = threading.Thread(target=m.task_worker, daemon=True); worker.start()
        result = m.execute_tool("task_wait", {"task_id":submitted["task_id"],"wait_seconds":5})
        m.STOP_EVENT.set(); worker.join(timeout=5)
        self.assertEqual(result["state"], "succeeded")
        self.assertIn("waited", result["stdout"])

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
        text = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_HOST=127.0.0.1", text)
        self.assertIn("SHOPVIVALIZ_REMOTE_MCP_PORT=5580", text)

    def test_controller_admin_runtime_is_not_filesystem_sandboxed(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8")
        setup = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        self.assertNotIn("ProtectSystem=full", unit)
        self.assertNotIn("ProtectHome=read-only", unit)
        self.assertNotIn("PrivateTmp=true", unit)
        self.assertIn("UNIT_SOURCE", setup)

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
        self.assertIn("sudo -n bash scripts/setup-remote-control-access.sh install-controller remote-control-mcp/server.py deploy/systemd/shopvivaliz-remote-control-mcp.service", text)
        self.assertNotIn("ubuntu@10.0.1.38)", text)
        self.assertIn("ubuntu@10.0.1.112", text)

    def test_oci_bastion_bootstrap_copies_and_passes_canonical_controller_unit(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn('"${BACKEND_SCP[@]}" remote-control-mcp/server.py scripts/setup-remote-control-access.sh deploy/systemd/shopvivaliz-remote-control-mcp.service ubuntu@127.0.0.1:/tmp/', text)
        self.assertIn('install-controller /tmp/server.py /tmp/shopvivaliz-remote-control-mcp.service', text)

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
            "action=durable-mcp-v2-production-e2e",
            "action=runtime-proof-submit",
            "action=runtime-proof-verify",
            "action=secure-mcp-tunnel-probe",
            "action=secure-mcp-runtime-recover",
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

    def test_oci_bastion_workflow_has_adversarial_durable_v2_production_e2e(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        for marker in (
            "DURABLE_V2_SQLITE_BACKUP=PASS",
            "DURABLE_V2_CONTROLLER_RESTART=PASS",
            "DURABLE_V2_DEDUP=PASS",
            "DURABLE_V2_TUNNEL_RESTART=PASS",
            "DURABLE_V2_MARKERS=PASS",
            "DURABLE_V2_FOUR_HOST_SMOKE=PASS",
        ):
            self.assertIn(marker, text)

    def test_oci_bastion_secure_tunnel_recovery_uses_canonical_runtime_script(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        start = text.index("- name: Recover Secure MCP Tunnel runtime through OCI Bastion")
        end = text.index("- name:", start + 10)
        block = text[start:end]
        self.assertIn("action=secure-mcp-runtime-recover", block)
        self.assertIn("scripts/setup-secure-mcp-tunnel-runtime.sh", block)
        self.assertIn("deploy/systemd/shopvivaliz-secure-mcp-tunnel.service", block)
        self.assertIn("SECURE_MCP_BASTION_RECOVERY=PASS", block)
        self.assertIn("sudo -n bash", block)
        self.assertNotIn("mcp-token", block)

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
            "OPENAI_TUNNEL_UI_GENERIC_CREATE_CONTROL=",
            "OPENAI_TUNNEL_UI_ORG_OWNER_SURFACE_AVAILABLE=",
            "OPENAI_TUNNEL_UI_ADMIN_KEYS_ACCESS_DENIED=",
            "OPENAI_TUNNEL_UI_TARGET_ORG_VISIBLE=",
            "OPENAI_TUNNEL_UI_ADMIN_KEYS_NAV_AVAILABLE=",
            "OPENAI_TUNNEL_UI_ADMIN_KEYS_LOCATION=",
            "OPENAI_TUNNEL_UI_ADMIN_KEYS_TEXT_PRESENT=",
            "OPENAI_TUNNEL_UI_RBAC_DIAG=",
        ):
            self.assertIn(marker, text)
        self.assertIn("https://platform.openai.com/settings/organization/admin-keys", text)
        self.assertIn("classifyAdminKeysLocation(", text)
        self.assertIn("ShopVivaliz ltda", text)
        self.assertIn("adminKeysNavAvailable", text)
        self.assertIn("adminKeysTextPresent", text)
        self.assertNotIn("OPENAI_TUNNEL_UI_RAW_", text)
        self.assertNotIn("CREATE_ADMIN_KEY", text)
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

        # Google OAuth may open a popup/new tab. The helper must follow that
        # popup and keep any human MFA challenge alive for a bounded window,
        # while exposing only sanitized state markers.
        for needle in (
            "waitForEvent('popup'",
            "MFA_WAIT_TIMEOUT_MS",
            "OPENAI_PLATFORM_GOOGLE_POPUP_USED=",
            "OPENAI_PLATFORM_MFA_WAIT_RESULT=",
        ):
            self.assertIn(needle, auth_text)
        for marker in (
            "OPENAI_PLATFORM_POST_GOOGLE_LOCATION=",
            "OPENAI_PLATFORM_FINAL_LOCATION=",
            "OPENAI_PLATFORM_AUTH_BLOCKER=",
        ):
            self.assertIn(marker, auth_text)
        self.assertIn("classifyLocation(", auth_text)
        for needle in (
            "waitForOAuthHandoff(",
            "waitForOAuthCompletion(",
            "OPENAI_PLATFORM_OAUTH_HANDOFF_RESULT=",
        ):
            self.assertIn(needle, auth_text)
        self.assertNotIn("waitForURL(/platform\\.openai\\.com|auth\\.openai\\.com/", auth_text)
        self.assertNotIn("OPENAI_PLATFORM_AUTH_RAW_OUTPUT=", auth_text)
        self.assertNotIn("OPENAI_PLATFORM_CURRENT_URL=", auth_text)

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

    def test_remote_access_runtime_status_uses_current_policy_inventory(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        policy = ROOT / "scripts" / "runtime-status-policy.sh"
        self.assertTrue(policy.exists(), "policy-aware runtime status script missing")
        text = policy.read_text(encoding="utf-8")

        self.assertNotIn("scripts/runtime-service-status.sh", workflow)
        self.assertNotIn("shopvivaliz-desktop-commander", text)
        self.assertNotIn("shopvivaliz-desktop-commander", workflow)

        self.assertIn("bash scripts/runtime-status-policy.sh site", workflow)
        self.assertIn("< scripts/runtime-status-policy.sh", workflow)
        self.assertIn("bash -s -- backend", workflow)

        for needle in (
            "shopvivaliz-agent.service",
            "shopvivaliz-catalog-reconcile.timer",
            "shopvivaliz-sync-safe.timer",
            "shopvivaliz-abandoned-cart-recovery.timer",
            "shopvivaliz-remote-control-mcp.service",
            "shopvivaliz-chatgpt-continuity.service",
            "mei-mg-email-worker.service",
            "sender_blocked.pause",
            "EXPECTED=inactive-sender-block",
            "RUNTIME_HEALTH=",
        ):
            self.assertIn(needle, text)

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
        self.assertIn('[claude_bin, "--remote-control", "ShopVivaliz-Trust-Bootstrap"]', helper_text)
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

    def test_chatgpt_continuity_repairs_normalize_site_token_metadata_without_changing_secret(self):
        remote = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        oci = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        remote_repair = remote.split("chatgpt_continuity_repair)", 1)[1].split("chatgpt_continuity_diagnostic)", 1)[0]
        oci_repair = oci.split("- name: ChatGPT continuity repair through OCI Bastion", 1)[1].split("- name:", 1)[0]

        for block in (remote_repair, oci_repair):
            for needle in (
                "sudo -n chown www-data:ubuntu",
                "sudo -n chmod 750",
                "sudo -n chmod 640",
                'sudo -u ubuntu test -r "$token_file"',
                'sha256sum -- "$token_file"',
                "CHATGPT_CONTINUITY_SITE_TOKEN_METADATA=PASS",
            ):
                self.assertIn(needle, block)
            self.assertIn("before=", block)
            self.assertIn("after=", block)
            self.assertIn('if [ "$before" != "$after" ]; then', block)
            self.assertNotIn('cat "$token_file"', block)

    def test_chatgpt_continuity_repair_uses_private_loopback_bridge_tunnel(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        repair = workflow.split("chatgpt_continuity_repair)", 1)[1].split("chatgpt_continuity_diagnostic)", 1)[0]
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_ROUTE=private_loopback_18081", repair)
        self.assertNotIn("bridge_endpoint=''", repair)
        self.assertNotIn("for candidate in", repair)
        self.assertNotIn("CHATGPT_CONTINUITY_BRIDGE_ENDPOINT=", repair)
        for forbidden in (
            "http://10.0.1.112/api/chatgpt-continuity/bridge.php",
            "http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php",
            "https://shopvivaliz.com.br/api/chatgpt-continuity/bridge.php",
        ):
            self.assertNotIn(forbidden, repair)

    def test_chatgpt_continuity_diagnostic_checks_user_service_scope(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("chatgpt_continuity_diagnostic)", 1)[1].split("secure_mcp_platform_ui_probe)", 1)[0]
        self.assertIn("systemctl --user is-active --quiet shopvivaliz-chatgpt-continuity.service", diagnostic)
        self.assertIn("XDG_RUNTIME_DIR=", diagnostic)
        self.assertIn("DBUS_SESSION_BUS_ADDRESS=", diagnostic)

    def test_chatgpt_continuity_diagnostic_uses_private_loopback_bridge_route(self):
        workflow = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("chatgpt_continuity_diagnostic)", 1)[1].split("secure_mcp_platform_ui_probe)", 1)[0]
        self.assertIn("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", diagnostic)
        self.assertIn("http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php", diagnostic)
        for forbidden in (
            "http://10.0.1.112/api/chatgpt-continuity/bridge.php",
            "http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php",
            "https://shopvivaliz.com.br/api/chatgpt-continuity/bridge.php",
        ):
            self.assertNotIn(forbidden, diagnostic)

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
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_ROUTE=private_loopback_18081", repair)
        self.assertNotIn("CHATGPT_CONTINUITY_BRIDGE_ENDPOINT=", repair)
        for forbidden in (
            "http://10.0.1.112/api/chatgpt-continuity/bridge.php",
            "http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php",
            "https://shopvivaliz.com.br/api/chatgpt-continuity/bridge.php",
        ):
            self.assertNotIn(forbidden, repair)

    def test_chatgpt_continuity_repairs_stage_full_installer_tree(self):
        remote = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        oci = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        remote_repair = remote.split("            chatgpt_continuity_repair)", 1)[1].split("              ;;", 1)[0]
        oci_repair = oci.split("- name: ChatGPT continuity repair through OCI Bastion", 1)[1].split("- name:", 1)[0]

        required_sources = (
            "scripts/install-chatgpt-continuity-backend-bridge.sh",
            "scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs",
            "scripts/chatgpt-continuity/chatgpt-browser-guardian.sh",
            "ops/systemd/shopvivaliz-chatgpt-browser.service",
            "ops/systemd/shopvivaliz-chatgpt-browser-guardian.service",
            "ops/systemd/shopvivaliz-chatgpt-browser-guardian.timer",
        )
        for block in (remote_repair, oci_repair):
            for needle in required_sources:
                self.assertIn(needle, block)
            self.assertIn("$remote_dir/scripts/install-chatgpt-continuity-backend-bridge.sh", block)
            self.assertIn("$remote_dir/scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs", block)
            self.assertIn("$remote_dir/ops/systemd", block)
            self.assertNotIn("$remote_dir/install.sh", block)
            self.assertNotIn("$remote_dir/worker.mjs", block)

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

    def test_oci_continuity_diagnostic_bounds_site_queue_certifier(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("- name: ChatGPT continuity diagnostic through Remote Control MCP", 1)[1].split("- name: Probe Secure MCP Tunnel prerequisites", 1)[0]
        self.assertIn("task_resume_queue.py", diagnostic)
        self.assertIn("timeout=8", diagnostic)
        self.assertNotIn("queue_summary=task_resume_queue.certify_queue(root)", diagnostic)

    def test_oci_continuity_diagnostic_scopes_progress_to_latest_generation(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("- name: ChatGPT continuity diagnostic through Remote Control MCP", 1)[1].split("- name: Probe Secure MCP Tunnel prerequisites", 1)[0]
        self.assertIn("latest_generation_fingerprint", diagnostic)
        self.assertIn("fingerprint==latest_generation_fingerprint", diagnostic)
        for marker in (
            "CHATGPT_CONTINUITY_LATEST_GENERATION_UPDATED_AT=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_COMPLETED_AT=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_EVIDENCE_COUNT=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_VERIFICATION_PRESENT=",
            "CHATGPT_CONTINUITY_LATEST_GENERATION_HISTORY_EVENTS=",
        ):
            self.assertIn(marker, diagnostic)

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

    def test_oci_continuity_diagnostic_surfaces_dispatcher_decision_counts(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("- name: ChatGPT continuity diagnostic through Remote Control MCP", 1)[1].split("- name: Probe Secure MCP Tunnel prerequisites", 1)[0]
        for marker in (
            "CHATGPT_CONTINUITY_DISPATCHER_REQUESTS_SCANNED=",
            "CHATGPT_CONTINUITY_DISPATCHER_CHATGPT_COMMON_ROWS=",
            "CHATGPT_CONTINUITY_DISPATCHER_EXPLICIT_REPOSITORY_ROWS=",
            "CHATGPT_CONTINUITY_DISPATCHER_CURRENT_CHECKPOINT_ROWS=",
            "CHATGPT_CONTINUITY_DISPATCHER_ELIGIBLE_ROWS=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_SUMMARY_AVAILABLE=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_SCANNED=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_ELIGIBLE=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_DISPATCHED=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_SKIPPED_NO_TOKEN=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_SKIPPED_STALE_CHECKPOINT=",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_SKIPPED_ATTEMPT_LIMIT=",
        ):
            self.assertIn(marker, diagnostic)
        self.assertNotIn("CHATGPT_CONTINUITY_DISPATCHER_REQUEST_TASK_ID=", diagnostic)
        self.assertNotIn("CHATGPT_CONTINUITY_DISPATCHER_REQUEST_FINGERPRINT=", diagnostic)

    def test_oci_continuity_diagnostic_requires_fresh_dispatcher_cycle(self):
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        diagnostic = workflow.split("- name: ChatGPT continuity diagnostic through Remote Control MCP", 1)[1].split("- name: Probe Secure MCP Tunnel prerequisites", 1)[0]
        for needle in (
            "dispatcher_last_cycle_at='NONE'",
            "dispatcher_log_fresh=False",
            "dispatcher_event_suffix='] ChatGPT continuity nudge dispatcher completed.'",
            "timestamp_re.fullmatch(candidate)",
            "dispatcher_last_cycle_at>=latest_generation_updated_at",
            "CHATGPT_CONTINUITY_DISPATCHER_LAST_CYCLE_AT=",
            "CHATGPT_CONTINUITY_DISPATCHER_LOG_FRESH=",
        ):
            self.assertIn(needle, diagnostic)
        self.assertNotIn(
            "dispatcher_log_seen='ChatGPT continuity nudge dispatcher completed.' in tail",
            diagnostic,
        )

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
            "task_resume_queue.py",
            "timeout=8",
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
        self.assertIn("import hashlib, json, pathlib, re, subprocess, sys", workflow)


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

    def test_freeze_state_readers_follow_latest_generation_not_terminal_g2(self):
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        remote = (ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        helper_body = helper.split("def freeze_state", 1)[1].split("def main", 1)[0]
        remote_body = remote.split("chatgpt_freeze_task_state)", 1)[1].split("chatgpt_freeze_state_owner_repair)", 1)[0]

        for body in (helper_body, remote_body):
            self.assertIn("chatgpt-freeze-root-cause-20260928-g*.json", body)
            self.assertIn("max(candidates)", body)
            self.assertNotIn("chatgpt-freeze-root-cause-20260928-g2", body)

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

    def test_oci_stage7_claude_install_does_not_require_optional_trust_bootstrap_marker(self):
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        install_body = helper.split("def claude_install", 1)[1].split("def claude_status", 1)[0]
        required_block = install_body.split("required = {", 1)[1].split("}", 1)[0]
        self.assertNotIn("CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS", required_block)
        for marker in (
            "CLAUDE_REMOTE_CONTROL_ELIGIBLE=PASS",
            "CLAUDE_PRIVATE_MCP_BRIDGE=PASS",
            "CLAUDE_REMOTE_CONTROL_CONSENT=PASS",
            "CLAUDE_REMOTE_CONTROL_SERVICE=PASS",
            "CLAUDE_REMOTE_CONTROL_INSTALL=PASS",
        ):
            self.assertIn(marker, required_block)
        self.assertIn('safe = safe_markers(stdout, ("CLAUDE_",))', install_body)

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
        self.assertIn('${{BASH_ENV+x}}', helper)
        self.assertNotIn('if [ "${BASH_ENV+x}" = x ]', helper)
        self.assertNotIn("CLAUDE_OCI_BASH_ENV_VALUE=", helper)
        self.assertNotIn("CLAUDE_OCI_BASH_STARTUP_OUTPUT=", helper)
        install_helper = helper.split("def claude_install", 1)[1].split("def claude_status", 1)[0]
        self.assertIn("awk '/^CLAUDE_[A-Z0-9_]+=/{{print}}'", install_helper)
        self.assertNotIn("awk '/^CLAUDE_[A-Z0-9_]+=/{print}'", install_helper)

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

    def test_claude_setup_clears_return_trap_before_leaving_auth_probe(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        probe = setup.split("probe_auth_and_command(){", 1)[1].split("\n}", 1)[0]
        self.assertIn('trap \'rm -f "$tmp"\' RETURN', probe)
        self.assertIn('trap - RETURN', probe)
        self.assertLess(probe.index('trap - RETURN'), probe.index('CLAUDE_REMOTE_CONTROL_ELIGIBLE=PASS'))


    def test_claude_setup_classifies_auth_json_state_without_set_e_silence(self):
        setup = (ROOT / "scripts" / "setup-claude-remote-control.sh").read_text(encoding="utf-8")
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        self.assertIn("claude_not_logged_in", setup)
        self.assertIn("claude_auth_status_invalid", setup)
        self.assertIn("isinstance(data, dict)", setup)
        self.assertIn('"claude_not_logged_in"', helper)
        self.assertIn('"claude_auth_status_invalid"', helper)

    def test_oci_bastion_reads_latest_freeze_generation_via_remote_control_mcp(self):

        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        helper = (ROOT / "scripts" / "oci-mcp-stage7-action.py").read_text(encoding="utf-8")
        self.assertIn("action=chatgpt-freeze-task-state", workflow)
        self.assertIn("freeze-state", workflow)
        self.assertIn("admin_command_run", helper)
        self.assertIn('SITE = "shopvivaliz-free-a1"', helper)
        self.assertIn("chatgpt-freeze-root-cause-20260928-g*.json", helper)
        self.assertIn("max(candidates)", helper)
        self.assertNotIn("canonical_task_id = 'chatgpt-freeze-root-cause-20260928-g2'", helper)
        self.assertIn("CHATGPT_FREEZE_CANONICAL_STATE=", helper)
        self.assertIn("TASK_TERMINAL_GATE=", helper)
        self.assertIn("sudo -u ubuntu -H python3", helper)
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
        self.assertIn('[claude_bin, "--remote-control", "ShopVivaliz-Trust-Bootstrap"]', helper)

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
        longform_call = "interactive = run_interactive_remote_control_mode(claude_bin, workspace)"
        server_call = "server = run_server_mode(claude_bin, workspace)"
        self.assertIn(plain_call, main_body)
        self.assertIn(longform_call, main_body)
        self.assertIn(server_call, main_body)
        self.assertLess(main_body.index(plain_call), main_body.index(longform_call))
        self.assertLess(main_body.index(longform_call), main_body.index(server_call))
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


class DurableExecutorV2Tests(unittest.TestCase):
    """Regression contract for durable work that outlives the HTTP controller."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        m.STATE_DIR = Path(self.tmp.name)
        m.DB_PATH = m.STATE_DIR / "state.db"
        m.SSH_KEY = m.STATE_DIR / "id_ed25519"
        m.KNOWN_HOSTS = m.STATE_DIR / "known_hosts"
        self._real_launch_task_service = m.launch_task_service
        self._original_systemd_unit_state = m.systemd_unit_state
        m.systemd_unit_state = lambda _unit: "active"
        m.STOP_EVENT.clear()
        m.init_db()

    def tearDown(self):
        m.STOP_EVENT.set()
        m.systemd_unit_state = self._original_systemd_unit_state
        self.tmp.cleanup()

    def submit(self, command="printf durable", request_id=None, timeout=30):
        return m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26", "command": command,
            "timeout": timeout, "request_id": request_id,
        })

    def claim(self, task_id, *, state="starting", started=False):
        unit = m.task_unit_name(task_id)
        with m.db_conn() as db:
            db.execute(
                "UPDATE tasks SET state=?,execution_unit=?,execution_started_at=?,result_dir=? WHERE id=?",
                (state, unit, m.now() if started else None, str(m.task_result_dir(task_id)), task_id),
            )
        return unit

    def test_init_db_does_not_requeue_running_task(self):
        task_id = self.submit()["task_id"]
        with m.db_conn() as db:
            db.execute(
                "UPDATE tasks SET state='running',started_at=?,heartbeat_at=? WHERE id=?",
                (m.now(), m.now(), task_id),
            )
        m.init_db()
        self.assertEqual(m.execute_tool("task_status", {"task_id": task_id})["state"], "running")

    def test_task_unit_name_is_deterministic_from_task_id(self):
        task_id = "123e4567-e89b-12d3-a456-426614174000"
        self.assertEqual(
            m.task_unit_name(task_id),
            "shopvivaliz-remote-task-123e4567e89b12d3a456426614174000.service",
        )
        with self.assertRaises(ValueError):
            m.task_unit_name("not-a-task-id")

    def test_request_id_conflicts_if_command_or_timeout_differs(self):
        self.submit(request_id="immutable-request")
        with self.assertRaisesRegex(ValueError, "request_id_conflict"):
            self.submit(command="printf changed", request_id="immutable-request")
        with self.assertRaisesRegex(ValueError, "request_id_conflict"):
            self.submit(request_id="immutable-request", timeout=31)

    def test_worker_does_not_launch_duplicate_when_live_unit_exists(self):
        task_id = self.submit()["task_id"]
        unit = self.claim(task_id, state="running", started=True)
        with mock.patch.object(m, "systemd_unit_state", return_value="active"), \
             mock.patch.object(m, "launch_task_service") as launch:
            worker = threading.Thread(target=m.task_worker, daemon=True)
            worker.start(); time.sleep(0.2); m.STOP_EVENT.set(); worker.join(timeout=2)
        launch.assert_not_called()
        self.assertEqual(m.execute_tool("task_status", {"task_id": task_id})["execution_unit"], unit)

    def test_reconcile_live_unit_adopts_running_task(self):
        task_id = self.submit()["task_id"]
        unit = self.claim(task_id)
        with mock.patch.object(m, "systemd_unit_state", return_value="active"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "running")
        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["state"], "running")
        self.assertEqual(status["execution_unit"], unit)

    def test_reconcile_persisted_result_finalizes_task(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, started=True)
        result_dir = m.ensure_task_result_dir(task_id)
        m.atomic_json(result_dir / "result.json", {"state": "succeeded", "exit_code": 0, "stdout": "ok", "stderr": ""})
        self.assertEqual(m.reconcile_task(m.load_task(task_id)), "succeeded")
        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["state"], "succeeded")
        self.assertEqual(status["stdout"], "ok")

    def test_reconcile_never_started_task_can_requeue(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id)
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "queued")
        self.assertEqual(m.execute_tool("task_status", {"task_id": task_id})["state"], "queued")

    def test_starting_task_waits_for_unit_visibility_before_safe_requeue(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id)
        with m.db_conn() as db:
            db.execute(
                "UPDATE tasks SET started_at=?,progress='launching' WHERE id=?",
                (m.now(), task_id),
            )
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "starting")
        with m.db_conn() as db:
            self.assertEqual(db.execute("SELECT state FROM tasks WHERE id=?", (task_id,)).fetchone()["state"], "starting")
        with m.db_conn() as db:
            db.execute("UPDATE tasks SET started_at=? WHERE id=?", ("1970-01-01T00:00:00+00:00", task_id))
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "queued")

    def test_cancel_before_physical_spawn_prevents_launcher(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id)
        with mock.patch.object(m, "stop_task_unit", return_value=True):
            self.assertEqual(m.execute_tool("task_cancel", {"task_id": task_id})["state"], "cancel_requested")
        with mock.patch.object(m, "launch_task_service") as launch:
            self.assertFalse(m.launch_claimed_task(task_id, 30))
        launch.assert_not_called()
        self.assertEqual(m.execute_tool("task_status", {"task_id": task_id})["state"], "cancelled")

    def test_controller_restart_after_cancel_before_spawn_never_launches_task(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id)
        with mock.patch.object(m, "stop_task_unit", return_value=True):
            m.execute_tool("task_cancel", {"task_id": task_id})
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"), \
             mock.patch.object(m, "launch_task_service") as launch:
            m.reconcile_tasks()
            worker = threading.Thread(target=m.task_worker, daemon=True)
            worker.start(); time.sleep(0.2); m.STOP_EVENT.set(); worker.join(timeout=2)
        launch.assert_not_called()
        self.assertEqual(m.execute_tool("task_status", {"task_id": task_id})["state"], "cancelled")

    def test_reconcile_started_missing_unit_marks_indeterminate(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "indeterminate")
        self.assertEqual(m.execute_tool("task_status", {"task_id": task_id})["state"], "indeterminate")

    def test_controller_restart_with_surviving_unit_does_not_spawn_second_execution(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        with mock.patch.object(m, "systemd_unit_state", return_value="active"), \
             mock.patch.object(m, "launch_task_service") as launch:
            m.reconcile_tasks()
            worker = threading.Thread(target=m.task_worker, daemon=True)
            worker.start(); time.sleep(0.2); m.STOP_EVENT.set(); worker.join(timeout=2)
        launch.assert_not_called()

    def test_task_cancel_uses_persisted_unit_not_only_active_procs(self):
        task_id = self.submit()["task_id"]
        unit = self.claim(task_id, state="running", started=True)
        with mock.patch.object(m, "stop_task_unit", return_value=True) as stop:
            result = m.execute_tool("task_cancel", {"task_id": task_id})
        self.assertEqual(result["state"], "cancel_requested")
        stop.assert_called_once_with(unit)

    def test_runner_persists_stdout_stderr_exit_code_and_result_json(self):
        task_id = self.submit(command="printf runner-output") ["task_id"]
        self.claim(task_id)
        self.assertEqual(m.run_task_entrypoint(task_id), 0)
        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["state"], "succeeded")
        self.assertIn("runner-output", status["stdout"])
        self.assertTrue((m.task_result_dir(task_id) / "result.json").is_file())

    def test_runner_cancel_requested_never_marks_execution_started_or_spawns(self):
        task_id = self.submit(command="printf must-not-run") ["task_id"]
        self.claim(task_id, state="cancel_requested")
        completed_process = mock.Mock()
        completed_process.poll.return_value = 0
        completed_process.returncode = 0
        with mock.patch.object(m.subprocess, "Popen", return_value=completed_process) as spawn:
            self.assertEqual(m.run_task_entrypoint(task_id), 0)
        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["state"], "cancelled")
        self.assertIsNone(status["execution_started_at"])
        spawn.assert_not_called()

    def test_read_capped_text_seeks_and_reads_at_most_max_output(self):
        path = Path(self.tmp.name) / "large.log"
        payload = b"discard-" * (m.MAX_OUTPUT + 10) + b"TAIL-MARKER"
        path.write_bytes(payload)
        with mock.patch.object(Path, "read_bytes", side_effect=AssertionError("must_not_read_whole_file")):
            result = m.read_capped_text(path)
        self.assertLessEqual(len(result.encode("utf-8")), m.MAX_OUTPUT)
        self.assertTrue(result.endswith("TAIL-MARKER"))

    def test_runner_heartbeat_advances_without_http_controller(self):
        task_id = self.submit(command="sleep 0.3") ["task_id"]
        self.claim(task_id)
        with m.db_conn() as db:
            db.execute("UPDATE tasks SET heartbeat_at=? WHERE id=?", ("1970-01-01T00:00:00+00:00", task_id))
        runner = threading.Thread(target=m.run_task_entrypoint, args=(task_id,), daemon=True)
        runner.start(); time.sleep(0.1)
        status = m.execute_tool("task_status", {"task_id": task_id})
        runner.join(timeout=2)
        self.assertNotEqual(status["heartbeat_at"], "1970-01-01T00:00:00+00:00")
        self.assertIsNotNone(status["execution_started_at"])

    def test_task_wait_treats_indeterminate_as_terminal(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            result = m.execute_tool("task_wait", {"task_id": task_id, "wait_seconds": 1})
        self.assertEqual(result["state"], "indeterminate")

    def test_systemd_command_does_not_include_raw_task_command(self):
        task_id = self.submit(command="echo forbidden-payload") ["task_id"]
        with mock.patch.object(m, "launch_task_service", self._real_launch_task_service), \
             mock.patch.object(m.subprocess, "run", return_value=mock.Mock(returncode=0, stderr="")) as run:
            m.launch_task_service(task_id, 30)
        argv = run.call_args.args[0]
        self.assertNotIn("forbidden-payload", " ".join(argv))
        self.assertEqual(argv[-2:], ["--run-task", task_id])

    def test_tunnel_unit_does_not_require_controller_hard_dependency(self):
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-secure-mcp-tunnel.service").read_text(encoding="utf-8")
        self.assertIn("Wants=shopvivaliz-remote-control-mcp.service", unit)
        self.assertNotIn("Requires=shopvivaliz-remote-control-mcp.service", unit)


if __name__ == "__main__":
    unittest.main()
