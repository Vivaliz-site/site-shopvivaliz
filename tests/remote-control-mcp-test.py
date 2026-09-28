#!/usr/bin/env python3
import importlib.util
import json
import os
import tempfile
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
        original = m.tailscale_peer_ip
        try:
            m.SSH_KEY.write_text("x")
            m.KNOWN_HOSTS.write_text("x")
            inv = m.remote_invocation("shopvivaliz-free-a1", "id -u")
            self.assertIn("sudo -n bash", inv[-1])
            self.assertIn("shopvivaliz-remote@10.0.1.112", inv)
        finally:
            m.tailscale_peer_ip = original

    def test_windows_uses_encoded_powershell(self):
        m.SSH_KEY.write_text("x")
        m.KNOWN_HOSTS.write_text("x")
        original = m.tailscale_peer_ip
        m.tailscale_peer_ip = lambda _: "100.64.0.10"
        try:
            inv = m.remote_invocation("Fred-Win", "Get-Date")
            self.assertIn("powershell.exe", inv)
            self.assertIn("-EncodedCommand", inv)
            self.assertIn("FRED@100.64.0.10", inv)
        finally:
            m.tailscale_peer_ip = original


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

    def test_windows_bootstrap_requires_administrator(self):
        text = (ROOT / "scripts" / "setup-remote-control-windows.ps1").read_text(encoding="utf-8")
        self.assertIn("administrator_required", text)
        self.assertIn("administrators_authorized_keys", text)
        self.assertIn("REMOTE_CONTROL_WINDOWS_KEY_INSTALL=PASS", text)

    def test_bootstrap_surfaces_do_not_discard_failures(self):
        paths = [
            ROOT / "scripts" / "setup-remote-control-access.sh",
            ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml",
        ]
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertNotIn("|| true", text, f"{path} must handle failures explicitly")


if __name__ == "__main__":
    unittest.main()
