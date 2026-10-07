#!/usr/bin/env python3
import importlib.util
import base64
import json
import os
import re
import sqlite3
import shlex
import subprocess
import sys
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
            "controller_status", "controller_promote", "continuity_status", "continuity_e2e",
            "claude_remote_control_status", "claude_remote_control_reconcile",
            "browser_tabs", "browser_controls", "browser_navigate", "browser_click", "browser_click_control", "browser_type",
            "foreground_handoff", "foreground_renew", "foreground_release",
        }:
            self.assertIn(required, names)

    def test_foreground_lease_lifecycle_tools_have_bounded_schemas(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertFalse(specs["foreground_handoff"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["foreground_renew"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["foreground_release"]["annotations"]["readOnlyHint"])
        self.assertEqual(set(specs["foreground_renew"]["inputSchema"]["required"]), {"task_id", "lease_id", "fencing_token", "ttl_seconds"})
        self.assertEqual(set(specs["foreground_release"]["inputSchema"]["required"]), {"task_id", "lease_id", "fencing_token", "reason"})

    def test_foreground_lifecycle_tools_dispatch_to_handoff_module(self):
        with mock.patch.object(m.foreground_handoff, "renew_foreground", return_value={"task_id":"task-a","lease_id":"lease-a"}) as renew:
            result=m.execute_tool("foreground_renew", {"task_id":"task-a","lease_id":"lease-a","fencing_token":3,"ttl_seconds":120})
        self.assertEqual(result["lease_id"],"lease-a")
        renew.assert_called_once_with("task-a", lease_id="lease-a", fencing_token=3, ttl_seconds=120)
        with mock.patch.object(m.foreground_handoff, "release_foreground", return_value={"task_id":"task-a","foreground_release_reason":"foreground_completed"}) as release:
            result=m.execute_tool("foreground_release", {"task_id":"task-a","lease_id":"lease-a","fencing_token":3,"reason":"foreground_completed"})
        self.assertEqual(result["foreground_release_reason"],"foreground_completed")
        release.assert_called_once_with("task-a", lease_id="lease-a", fencing_token=3, reason="foreground_completed")

    def test_controller_service_env_carries_single_durable_handoff_flag(self):
        setup=(ROOT/"scripts"/"setup-remote-control-access.sh").read_text()
        self.assertIn("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", setup)
        self.assertIn("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=${SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF:-0}", setup)
        browser=(ROOT/"deploy"/"systemd"/"shopvivaliz-remote-control-browser-mcp.service").read_text()
        self.assertIn("EnvironmentFile=/var/lib/shopvivaliz-remote-control/service.env", browser)

    def test_controller_service_env_points_agent_state_at_shared_runtime(self):
        setup=(ROOT/"scripts"/"setup-remote-control-access.sh").read_text()
        self.assertIn("SHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state", setup)

    def test_installed_server_resolves_colocated_continuity_runtime(self):
        with tempfile.TemporaryDirectory() as td:
            server_path=Path(td)/"shopvivaliz-remote-control"/"server.py"
            runtime_dir=server_path.parent/"scripts"/"continuity"
            runtime_dir.mkdir(parents=True)
            server_path.touch()
            self.assertEqual(m.resolve_continuity_lib_dir(server_path, ""), runtime_dir)

    def test_controller_installer_bundles_continuity_runtime_dependencies(self):
        setup=(ROOT/"scripts"/"setup-remote-control-access.sh").read_text()
        for required in ("AGENT_TASK_STATE_SOURCE", "CONTINUITY_SOURCE_DIR", "agent_task_state.py", "conversation_lease.py", "runtime_lock.py", "mutation_gate.py", "foreground_handoff.py"):
            self.assertIn(required, setup)
        bootstrap=(ROOT/".github"/"workflows"/"remote-control-mcp-bootstrap.yml").read_text()
        self.assertIn("scripts/agent_task_state.py scripts/continuity", bootstrap)
        bastion=(ROOT/".github"/"workflows"/"oci-bastion-private-access-bootstrap.yml").read_text()
        for required in ("agent_task_state.py", "conversation_lease.py", "runtime_lock.py", "mutation_gate.py", "foreground_handoff.py"):
            self.assertIn(required, bastion)

    def test_browser_allows_microsoft_oauth_host(self):
        self.assertIn("login.microsoftonline.com", m.BROWSER_ALLOWED_HOSTS)

    def test_browser_session_sources_can_be_pinned_per_mcp(self):
        old_name = m.BROWSER_SESSION_NAME
        old_url = m.BROWSER_CDP_URL
        try:
            m.BROWSER_SESSION_NAME = "dev"
            m.BROWSER_CDP_URL = "http://127.0.0.1:9559"
            self.assertIn('session="dev"; endpoint="http://127.0.0.1:9559/json"', m.browser_tabs_command())
            self.assertNotIn("9556/json", m.browser_tabs_command())
            self.assertIn("127.0.0.1:9559/json/new?", m.browser_open_command("https://claude.ai/login"))
        finally:
            m.BROWSER_SESSION_NAME = old_name
            m.BROWSER_CDP_URL = old_url

    def test_browser_open_targets_canonical_atendimento_cdp_session(self):
        command = m.browser_open_command("https://claude.ai/login")
        self.assertIn("http://127.0.0.1:9556/json/new?", command)
        self.assertNotIn("9559", command)
        self.assertIn("SHOPVIVALIZ_OPEN_URL_B64", command)
        self.assertIn("'session':\"atendimento\"", command)

    def test_browser_open_is_exposed_by_canonical_base_mcp(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertIn("browser_open", specs)
        self.assertEqual(["url"], specs["browser_open"]["inputSchema"]["required"])

    def test_mcp_tool_names_are_unique(self):
        names = [item["name"] for item in m.tool_specs()]
        self.assertEqual(len(names), len(set(names)))

    def test_admin_tools_are_annotated_mutating(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertFalse(specs["admin_command_run"]["annotations"]["readOnlyHint"])
        self.assertTrue(specs["admin_command_run"]["annotations"]["destructiveHint"])
        self.assertTrue(specs["host_health"]["annotations"]["readOnlyHint"])

    def test_durable_admin_commands_support_long_application_installs(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertEqual(
            specs["task_submit"]["inputSchema"]["properties"]["timeout"]["maximum"],
            m.MAX_DURABLE_TIMEOUT,
        )
        self.assertEqual(
            specs["admin_command_run"]["inputSchema"]["properties"]["timeout"]["maximum"],
            m.MAX_DURABLE_TIMEOUT,
        )
        task = m.execute_tool(
            "admin_command_run",
            {
                "host": "always-free-arm-1787907847-26",
                "command": "printf install-capability-check",
                "timeout": 3600,
                "durable": True,
                "request_id": "long-install-capability",
            },
        )
        self.assertTrue(task["durable"])
        row = m.load_task(task["task_id"])
        self.assertEqual(int(row["timeout"]), 3600)

    def test_long_inline_admin_command_requires_durable_mode(self):
        with self.assertRaisesRegex(ValueError, "timeout_out_of_range"):
            m.execute_tool(
                "admin_command_run",
                {
                    "host": "always-free-arm-1787907847-26",
                    "command": "printf inline-too-long",
                    "timeout": 3600,
                    "durable": False,
                },
            )

    def test_admin_command_rejects_browser_mcp_continuity_session_coupling(self):
        command = (
            "cp /etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service.d/"
            "40-authenticated-session.conf.disabled "
            "/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service.d/"
            "40-authenticated-session.conf && systemctl daemon-reload"
        )
        with self.assertRaisesRegex(ValueError, "browser_mcp_session_coupling_forbidden"):
            m.execute_tool("admin_command_run", {
                "host": "always-free-arm-1787907847-26",
                "command": command,
                "timeout": 20,
            })

    def test_durable_task_rejects_browser_mcp_continuity_session_coupling(self):
        command = (
            "install -m 0644 /tmp/override "
            "/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service.d/"
            "40-authenticated-session.conf"
        )
        with self.assertRaisesRegex(ValueError, "browser_mcp_session_coupling_forbidden"):
            m.execute_tool("task_submit", {
                "host": "always-free-arm-1787907847-26",
                "command": command,
                "timeout": 60,
            })

    def test_controller_and_continuity_tools_have_safe_annotations(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertTrue(specs["controller_status"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["controller_status"]["annotations"]["destructiveHint"])
        self.assertTrue(specs["continuity_status"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["continuity_status"]["annotations"]["destructiveHint"])
        self.assertFalse(specs["controller_promote"]["annotations"]["readOnlyHint"])
        self.assertTrue(specs["controller_promote"]["annotations"]["destructiveHint"])
        self.assertFalse(specs["continuity_e2e"]["annotations"]["readOnlyHint"])
        self.assertTrue(specs["continuity_e2e"]["annotations"]["destructiveHint"])
        self.assertTrue(specs["claude_remote_control_status"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["claude_remote_control_status"]["annotations"]["destructiveHint"])
        self.assertFalse(specs["claude_remote_control_reconcile"]["annotations"]["readOnlyHint"])
        self.assertTrue(specs["claude_remote_control_reconcile"]["annotations"]["destructiveHint"])

    def test_browser_tools_have_safe_annotations_and_canonical_schemas(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        for name in ("browser_tabs", "browser_controls"):
            self.assertTrue(specs[name]["annotations"]["readOnlyHint"])
            self.assertFalse(specs[name]["annotations"]["destructiveHint"])
        for name in ("browser_navigate", "browser_click", "browser_click_control", "browser_type"):
            self.assertFalse(specs[name]["annotations"]["readOnlyHint"])
            self.assertTrue(specs[name]["annotations"]["destructiveHint"])
        self.assertNotIn("host", specs["browser_tabs"]["inputSchema"]["properties"])
        self.assertEqual(
            specs["browser_type"]["inputSchema"]["required"],
            ["tab_id", "selector", "text"],
        )
        self.assertEqual(
            specs["browser_click_control"]["inputSchema"]["required"],
            ["tab_id", "index"],
        )

    def test_desktop_tools_have_safe_annotations_and_bounded_schemas(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        for name in ("desktop_health", "desktop_screenshot"):
            self.assertTrue(specs[name]["annotations"]["readOnlyHint"])
            self.assertFalse(specs[name]["annotations"]["destructiveHint"])
        for name in ("desktop_open", "desktop_click", "desktop_type"):
            self.assertFalse(specs[name]["annotations"]["readOnlyHint"])
            self.assertTrue(specs[name]["annotations"]["destructiveHint"])
        for name in ("desktop_health", "desktop_open", "desktop_screenshot", "desktop_click", "desktop_type"):
            host = specs[name]["inputSchema"]["properties"]["host"]
            self.assertEqual(set(host["enum"]), {"Fred-Win", "KOCEPSV"})
            for forbidden in ("rustdesk_id", "display", "window", "selector", "command"):
                self.assertNotIn(forbidden, specs[name]["inputSchema"]["properties"])
        self.assertEqual(specs["desktop_health"]["inputSchema"]["required"], ["host"])
        self.assertEqual(specs["desktop_open"]["inputSchema"]["required"], ["host"])
        self.assertEqual(specs["desktop_screenshot"]["inputSchema"]["required"], ["host"])
        self.assertEqual(specs["desktop_click"]["inputSchema"]["required"], ["host", "x", "y"])
        self.assertEqual(specs["desktop_type"]["inputSchema"]["required"], ["host", "text"])

    def test_desktop_host_validation_rejects_non_support_hosts(self):
        self.assertEqual(m.validate_desktop_host("KOCEPSV")["platform"], "windows")
        self.assertEqual(m.validate_desktop_host("Fred-Win")["platform"], "windows")
        for host in ("always-free-arm-1787907847-26", "shopvivaliz-free-a1", "unknown"):
            with self.subTest(host=host):
                with self.assertRaisesRegex(ValueError, "unsupported_desktop_host"):
                    m.validate_desktop_host(host)

    def test_rustdesk_host_id_requires_runtime_mapping_and_valid_id(self):
        old = os.environ.pop("SHOPVIVALIZ_RUSTDESK_HOST_IDS", None)
        try:
            with self.assertRaisesRegex(ValueError, "rustdesk_host_id_unavailable"):
                m.rustdesk_host_id("KOCEPSV")
            os.environ["SHOPVIVALIZ_RUSTDESK_HOST_IDS"] = json.dumps({"KOCEPSV": "123456789"})
            self.assertEqual(m.rustdesk_host_id("KOCEPSV"), "123456789")
            with self.assertRaisesRegex(ValueError, "rustdesk_host_id_unavailable"):
                m.rustdesk_host_id("Fred-Win")
            os.environ["SHOPVIVALIZ_RUSTDESK_HOST_IDS"] = json.dumps({"KOCEPSV": "bad id"})
            with self.assertRaisesRegex(ValueError, "invalid_rustdesk_host_id"):
                m.rustdesk_host_id("KOCEPSV")
        finally:
            if old is None:
                os.environ.pop("SHOPVIVALIZ_RUSTDESK_HOST_IDS", None)
            else:
                os.environ["SHOPVIVALIZ_RUSTDESK_HOST_IDS"] = old

    def test_audit_redacts_desktop_type_text(self):
        safe = m.sanitize_audit_args(
            "desktop_type",
            {"host": "KOCEPSV", "text": "sample-sensitive-input", "press_enter": True},
        )
        self.assertNotIn("text", safe)
        self.assertNotIn("text_sha256", safe)
        self.assertEqual(safe["text_length"], len("sample-sensitive-input"))

    def test_mutating_browser_tool_requires_runtime_lock_when_handoff_enabled(self):
        old = os.environ.get("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF")
        old_state = os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR")
        os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = "1"
        os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = self.tmp.name
        try:
            with mock.patch.object(m, "run_host_command") as runner:
                with self.assertRaisesRegex(ValueError, "runtime_lock_required"):
                    m.execute_tool("browser_click_control", {"tab_id": "ABC123", "index": 0})
            runner.assert_not_called()
        finally:
            if old is None: os.environ.pop("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", None)
            else: os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = old
            if old_state is None: os.environ.pop("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", None)
            else: os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = old_state

    def test_browser_mutation_requires_conversation_gate_when_handoff_enabled(self):
        old = os.environ.get("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF")
        old_state = os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR")
        os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = "1"
        os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = self.tmp.name
        try:
            runtime = m.runtime_lock.acquire_runtime_lock("durable-recovery", "worker-a", 30, ["browser_click_control"])
            with mock.patch.object(m, "run_host_command", return_value={"exit_code": 0, "stdout": "{}", "stderr": ""}) as runner:
                with self.assertRaisesRegex(ValueError, "conversation_mutation_gate_required"):
                    m.execute_tool("browser_click_control", {
                        "tab_id": "ABC123", "index": 0,
                        "runtime_lease_id": runtime["lease_id"],
                        "runtime_fencing_token": runtime["fencing_token"],
                    })
            runner.assert_not_called()
        finally:
            if old is None: os.environ.pop("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", None)
            else: os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = old
            if old_state is None: os.environ.pop("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", None)
            else: os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = old_state

    def test_mutating_browser_tool_accepts_current_runtime_lock_and_rejects_stale_token(self):
        old = os.environ.get("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF")
        old_state = os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR")
        os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = "1"
        os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = self.tmp.name
        try:
            task_id = "task-runtime-lock"
            conversation_id = "conversation_12345678"
            checkpoint = {
                "task_id": task_id, "status": "RUNNING", "conversation_id": conversation_id,
                "browser_session": "fred", "history": [{"action": "start"}],
            }
            (Path(self.tmp.name) / f"{task_id}.json").write_text(json.dumps(checkpoint))
            conversation = m.conversation_lease.acquire_conversation_lease(
                conversation_id, "durable-recovery", "worker-a", 1, 30, ["browser_click_control"]
            )
            lock = m.runtime_lock.acquire_runtime_lock("durable-recovery", "worker-a", 30, ["browser_click_control"])
            args = {
                "tab_id": "ABC123", "index": 0, "task_id": task_id,
                "conversation_id": conversation_id, "checkpoint_version": 1,
                "session_identity": "fred",
                "conversation_lease_id": conversation["lease_id"],
                "conversation_fencing_token": conversation["fencing_token"],
                "runtime_lease_id": lock["lease_id"],
                "runtime_fencing_token": lock["fencing_token"],
            }
            with mock.patch.object(m, "run_host_command", return_value={"exit_code": 0, "stdout": "{}", "stderr": ""}) as runner:
                m.execute_tool("browser_click_control", args)
            self.assertTrue(runner.called)
            stale = dict(args); stale["runtime_fencing_token"] = lock["fencing_token"] - 1
            with self.assertRaisesRegex(ValueError, "runtime_lock_invalid"):
                m.execute_tool("browser_click_control", stale)
        finally:
            if old is None: os.environ.pop("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", None)
            else: os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = old
            if old_state is None: os.environ.pop("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", None)
            else: os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = old_state

    def test_controller_promote_requires_full_expected_sha(self):
        with self.assertRaisesRegex(ValueError, "invalid_expected_sha"):
            m.execute_tool("controller_promote", {"expected_sha": "abc123"})

    def test_continuity_e2e_requires_valid_conversation_id(self):
        with self.assertRaisesRegex(ValueError, "invalid_conversation_id"):
            m.execute_tool("continuity_e2e", {"conversation_id": "bad/value"})

    def test_claude_reconcile_requires_full_expected_sha(self):
        with self.assertRaisesRegex(ValueError, "invalid_expected_sha"):
            m.execute_tool("claude_remote_control_reconcile", {"expected_sha": "abc123"})

    def test_claude_status_is_sanitized(self):
        pointer = Path(self.tmp.name) / "bridge-pointer.json"
        process_fields = Path(f"/proc/{os.getpid()}/stat").read_text(encoding="utf-8").split()
        pointer.write_text(json.dumps({
            "sessionId": "secret-session-id",
            "environmentId": "secret-environment-id",
            "source": "standalone",
            "pid": os.getpid(),
            "procStart": process_fields[21],
        }), encoding="utf-8")
        old = m.CLAUDE_REMOTE_CONTROL_POINTER_FILE
        m.CLAUDE_REMOTE_CONTROL_POINTER_FILE = pointer
        try:
            def fake_run(argv, *, timeout=60):
                if "is-active" in argv:
                    return mock.Mock(returncode=0, stdout=b"active\n", stderr=b"")
                if "ExecStart" in argv:
                    return mock.Mock(returncode=0, stdout=b"/home/ubuntu/.local/bin/claude remote-control --spawn worktree", stderr=b"")
                return mock.Mock(returncode=0, stdout=b"", stderr=b"")
            with mock.patch.object(m, "_run_local", side_effect=fake_run):
                status = m.claude_remote_control_status()
        finally:
            m.CLAUDE_REMOTE_CONTROL_POINTER_FILE = old

        self.assertTrue(status["ok"])
        self.assertTrue(status["service_active"])
        self.assertTrue(status["identity_match"])
        self.assertTrue(status["session_present"])
        self.assertTrue(status["environment_present"])
        self.assertTrue(status["session_recovery_enabled"])
        for forbidden in ("sessionId", "environmentId", "pid", "procStart"):
            self.assertNotIn(forbidden, status)

    def test_controller_state_strips_internal_identifiers(self):
        original = m.CONTROLLER_STATE_FILE
        state = Path(self.tmp.name) / "controller-state.json"
        state.write_text(json.dumps({
            "continuity_ready": False,
            "degraded_reasons": ["example"],
            "claude_remote_control": {
                "connected": True,
                "sessionId": "session-secret",
                "environmentId": "environment-secret",
                "pid": 123,
                "procStart": "456",
            },
        }), encoding="utf-8")
        m.CONTROLLER_STATE_FILE = state
        try:
            result = m._read_controller_state()
        finally:
            m.CONTROLLER_STATE_FILE = original
        claude = result["claude_remote_control"]
        self.assertTrue(claude["connected"])
        self.assertNotIn("sessionId", claude)
        self.assertNotIn("environmentId", claude)
        self.assertNotIn("pid", claude)
        self.assertNotIn("procStart", claude)

    def test_controller_promote_rejects_sha_not_current_main_before_worktree(self):
        requested = "a" * 40
        with mock.patch.object(m, "_controller_origin_main_sha", return_value="b" * 40),              mock.patch.object(m, "_controller_worktree") as worktree:
            with self.assertRaisesRegex(ValueError, "expected_sha_not_origin_main"):
                m.controller_promote(requested)
        worktree.assert_not_called()

    def test_controller_promote_is_persisted_as_durable_task(self):
        sha = "a" * 40
        with mock.patch.object(m, "_controller_origin_main_sha", return_value=sha),              mock.patch.object(m, "controller_status", return_value={"active_sha": "b" * 40, "service_active": True}):
            result = m.controller_promote(sha, timeout=120)
        self.assertTrue(result["durable"])
        self.assertEqual(result["state"], "queued")
        row = m.load_task(result["task_id"])
        self.assertIn("--controller-promote-run", row["command"])
        self.assertIn(sha, row["command"])

    def test_continuity_e2e_is_persisted_as_durable_task(self):
        sha = "a" * 40
        cid = "conversation_12345678"
        with mock.patch.object(m, "_controller_origin_main_sha", return_value=sha),              mock.patch.object(m, "controller_status", return_value={"active_sha": sha, "service_active": True}):
            result = m.continuity_e2e(cid, timeout_seconds=120)
        self.assertTrue(result["durable"])
        self.assertEqual(result["state"], "queued")
        row = m.load_task(result["task_id"])
        self.assertIn("--continuity-e2e-run", row["command"])
        self.assertIn(cid, row["command"])

    def test_continuity_e2e_report_parser_accepts_multiline_json_after_probe_output(self):
        stdout = (
            "checkpoint created\n"
            "{\n"
            '  "pass": false,\n'
            '  "observed_request": true,\n'
            '  "final_state": {"status": "RUNNING"}\n'
            "}\n"
        )
        report = m._parse_trailing_json_report(stdout)
        self.assertFalse(report["pass"])
        self.assertTrue(report["observed_request"])
        self.assertEqual(report["final_state"]["status"], "RUNNING")

    def test_continuity_e2e_report_parser_fails_closed_on_non_json_output(self):
        self.assertEqual(m._parse_trailing_json_report("checkpoint only\nnot json\n"), {})

    def test_claude_reconcile_is_persisted_as_durable_task(self):
        sha = "a" * 40
        with mock.patch.object(m, "_controller_origin_main_sha", return_value=sha),              mock.patch.object(m, "claude_remote_control_status", return_value={"ok": False}):
            result = m.claude_remote_control_reconcile(sha, timeout=120)
        self.assertTrue(result["durable"])
        self.assertEqual(result["state"], "queued")
        row = m.load_task(result["task_id"])
        self.assertIn("--claude-reconcile-run", row["command"])
        self.assertIn(sha, row["command"])

    def test_continuity_status_is_fail_closed_until_ready(self):
        with mock.patch.object(m, "controller_status", return_value={
            "service_active": True,
            "state": {"liveness_ok": True, "continuity_ready": False, "degraded": True, "degraded_reasons": ["auth"]},
        }):
            status = m.continuity_status()
        self.assertFalse(status["ok"])
        self.assertFalse(status["continuity_ready"])

    def test_process_listing_omits_linux_arguments(self):
        command = shlex.split(m.processes_command("linux"))
        fields = command[command.index("-eo") + 1].split(",")
        self.assertEqual(fields, ["pid", "user", "pcpu", "pmem", "etime", "comm"])

    def test_process_metadata_does_not_include_child_argument(self):
        sentinel = "audit-v5-synthetic-private-argument"
        command = shlex.split(m.processes_command("linux"))
        fields = command[command.index("-eo") + 1]
        child = subprocess.Popen(
            [sys.executable, "-c", "import time; time.sleep(30)", sentinel],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        try:
            # Self-hosted runners can be heavily loaded while multiple audit
            # workflows start at once. This is a privacy assertion, not a
            # latency assertion, so allow scheduling delay without turning a
            # healthy metadata-only `ps` invocation into a false negative.
            result = subprocess.run(
                ["ps", "-p", str(child.pid), "-o", fields],
                capture_output=True, text=True, timeout=30, check=True,
            )
            self.assertIn(str(child.pid), result.stdout)
            self.assertTrue(sentinel not in result.stdout,
                            "process metadata includes private argv")
        finally:
            child.terminate()
            child.wait(timeout=8)

    def test_process_listing_windows_is_metadata_only(self):
        command = m.processes_command("windows")
        self.assertIn("Id,ProcessName,CPU,WorkingSet64", command)
        self.assertNotIn("CommandLine", command)

    def test_redaction_hides_sensitive_command_flags(self):
        cases = (
            ("beam.smp -setcookie synthetic_cookie_123 -name node@host", "synthetic_cookie_123"),
            ("worker --password 'synthetic password' --workers 4", "synthetic password"),
            ('worker --client-secret "synthetic client secret" --workers 4', "synthetic client secret"),
            ("worker --api-key=synthetic_api_key --workers 4", "synthetic_api_key"),
            ("worker --access-token synthetic_access_token --workers 4", "synthetic_access_token"),
        )
        for sample, sentinel in cases:
            with self.subTest(flag=sample.split()[1]):
                output = m.redact_text(sample)
                self.assertTrue(sentinel not in output, "sensitive flag value was not redacted")
                self.assertIn("[REDACTED]", output)
        self.assertEqual(m.redact_text("worker --workers 4 -name node@host"),
                         "worker --workers 4 -name node@host")

    def test_cli_flag_redaction_preserves_bearer_protection(self):
        sentinel = "synthetic_access_abcdefghijklmnop"
        for flag in ("--token", "--access-token", "-token"):
            with self.subTest(flag=flag):
                output = m.redact_text(f"worker {flag} Bearer {sentinel}")
                self.assertNotIn(sentinel, output)
                self.assertIn("[REDACTED]", output)
        self.assertNotIn(sentinel, m.redact_text(f"Authorization: Bearer {sentinel}"))
        self.assertEqual(m.redact_text("worker --tokenizer normal"),
                         "worker --tokenizer normal")

    def test_secret_redaction(self):
        sample = "Authorization: Bearer abcdefghijklmnopqrstuvwxyz sk-projectsecret123456"
        redacted = m.redact_text(sample)
        self.assertNotIn("abcdefghijklmnopqrstuvwxyz", redacted)
        self.assertNotIn("projectsecret123456", redacted)
        self.assertIn("REDACTED", redacted)

    def test_audit_redacts_browser_text_and_navigation_query(self):
        safe = m.sanitize_audit_args(
            "browser_type",
            {"tab_id": "ABC123", "selector": "#code", "text": "sample-sensitive-input", "submit": True},
        )
        self.assertNotIn("text", safe)
        self.assertNotIn("text_sha256", safe)
        self.assertEqual(safe["text_length"], len("sample-sensitive-input"))
        nav = m.sanitize_audit_args(
            "browser_navigate",
            {"tab_id": "ABC123", "url": "https://auth.openai.com/log-in?state=opaque#fragment"},
        )
        self.assertEqual(nav["url"], "https://auth.openai.com/log-in")
        self.assertNotIn("opaque", json.dumps(nav))

    def test_linux_service_action_is_fail_closed_and_checks_user_scope(self):
        command = m.service_command(
            "linux",
            "shopvivaliz-chatgpt-continuity.service",
            "restart",
            "ubuntu",
        )
        self.assertIn("LoadState", command)
        self.assertIn("runuser -u ubuntu", command)
        self.assertIn("XDG_RUNTIME_DIR=/run/user/$uid", command)
        self.assertIn("systemctl --user restart", command)
        self.assertIn("service_not_found", command)

    def test_service_action_handler_passes_canonical_user_owner(self):
        captured = {}
        def fake_run(host, command, timeout=30, cancel_check=None):
            captured["command"] = command
            return {"host": host, "exit_code": 0, "stdout": "active", "stderr": "", "duration_ms": 1}
        with mock.patch.object(m, "run_host_command", side_effect=fake_run):
            result = m.execute_tool(
                "service_action",
                {
                    "host": "always-free-arm-1787907847-26",
                    "service": "shopvivaliz-chatgpt-continuity.service",
                    "action": "restart",
                },
            )
        self.assertTrue(result["ok"])
        self.assertIn("runuser -u ubuntu", captured["command"])
        self.assertIn("systemctl --user restart", captured["command"])

    def test_browser_commands_are_allowlisted_and_metadata_only(self):
        tabs = m.browser_tabs_command()
        expression = m.browser_controls_expression()
        self.assertNotIn("'title':", tabs)
        self.assertNotIn("document.title", expression)
        self.assertNotIn(".value", expression)
        self.assertIn("openai.com", tabs)
        self.assertIn("[REDACTED_EMAIL]", expression)
        daybreak = m.browser_navigate_command("ABC123", "https://openai.com/form/enterprise-trusted-access-for-cyber/")
        self.assertIn("openai.com", daybreak)
        with self.assertRaisesRegex(ValueError, "browser_url_not_allowlisted"):
            m.browser_navigate_command("ABC123", "https://mail.google.com/mail/u/0/")
        with self.assertRaisesRegex(ValueError, "browser_url_query_not_allowed"):
            m.browser_navigate_command("ABC123", "https://auth.openai.com/log-in?state=opaque")

    def test_browser_focused_navigate_targets_atendimento_and_requires_focused_tab(self):
        command = m.browser_focused_navigate_command("https://openai.com/form/enterprise-trusted-access-for-cyber/")
        self.assertIn("127.0.0.1:9556/json", command)
        self.assertIn("document.hasFocus()", command)
        self.assertIn("focused_tab_not_found", command)
        self.assertIn("focused_tab_ambiguous", command)
        self.assertIn("openai.com", command)

    def test_browser_navigate_tab_id_is_optional_for_focused_atendimento_route(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        required = specs["browser_navigate"]["inputSchema"]["required"]
        self.assertIn("url", required)
        self.assertNotIn("tab_id", required)

    def test_browser_tab_specific_commands_are_pinned_to_one_session(self):
        tabs = m.browser_tabs_command()
        self.assertIn("127.0.0.1:9556/json", tabs)
        self.assertNotIn("127.0.0.1:9559/json", tabs)
        self.assertNotIn("127.0.0.1:9555/json", tabs)
        command = m._browser_cdp_command("ABC123", "(()=>true)()")
        self.assertIn("127.0.0.1:9556/json", command)
        self.assertNotIn("127.0.0.1:9559/json", command)

    def test_browser_explicit_type_is_pinned_by_session_environment(self):
        script = m.BROWSER_TYPE_NODE_SCRIPT
        self.assertIn("SHOPVIVALIZ_BROWSER_CDP_URL", script)
        self.assertIn("SHOPVIVALIZ_BROWSER_SESSION_NAME", script)
        self.assertNotIn("127.0.0.1:9559/json", script)
        self.assertNotIn("127.0.0.1:9555/json", script)

    def test_browser_cdp_opens_websocket_before_constructing_cdp(self):
        command = m._browser_cdp_command("ABC123", "(()=>true)()")
        self.assertIn("new WebSocket(t.webSocketDebuggerUrl)", command)
        self.assertIn("addEventListener('open'", command)
        self.assertIn("new Cdp(ws)", command)
        self.assertNotIn("await c.connect()", command)

    def test_browser_cdp_runtime_exceptions_fail_closed(self):
        command = m._browser_cdp_command("ABC123", "(()=>{throw new Error('boom')})()")
        self.assertIn("exceptionDetails", command)
        self.assertIn("browser_runtime_exception", command)

    def test_browser_click_control_uses_sanitized_control_index(self):
        command = m.browser_click_control_command("ABC123", 2)
        match = re.search(r"SHOPVIVALIZ_EXPR_B64=([A-Za-z0-9+/=]+)", command)
        self.assertIsNotNone(match)
        expression = base64.b64decode(match.group(1)).decode()
        self.assertIn("querySelectorAll('input,button,[role=button]')", expression)
        self.assertIn("controls[2]", expression)
        self.assertIn("control_index_not_found", expression)
        self.assertIn(".focus({preventScroll:true})", expression)
        self.assertLess(expression.index(".focus({preventScroll:true})"), expression.index("e.click()"))
        with self.assertRaisesRegex(ValueError, "invalid_control_index"):
            m.browser_click_control_command("ABC123", 120)

    def test_browser_type_runtime_exceptions_fail_closed(self):
        self.assertIn("exceptionDetails", m.BROWSER_TYPE_NODE_SCRIPT)
        self.assertIn("browser_runtime_exception", m.BROWSER_TYPE_NODE_SCRIPT)

    def test_browser_type_uses_modern_node_with_websocket_support(self):
        self.assertEqual(m.BROWSER_NODE_BIN, "/usr/local/bin/node")

    def test_browser_type_uses_stdin_not_command_line(self):
        argv = m.browser_type_invocation("ABC123", "#code", True)
        joined = " ".join(argv)
        self.assertIn("process.stdin", joined)
        self.assertNotIn("sample-sensitive-input", joined)
        with mock.patch.object(m, "run_local_command_with_stdin", return_value={
            "host": m.CONTROLLER_BACKEND_HOST,
            "exit_code": 0,
            "stdout": '{"typed":true,"submitted":true}',
            "stderr": "",
            "duration_ms": 1,
        }) as runner:
            result = m.execute_tool(
                "browser_type",
                {"tab_id": "ABC123", "selector": "#code", "text": "sample-sensitive-input", "submit": True},
            )
        self.assertTrue(result["ok"])
        runner.assert_called_once()
        self.assertEqual(runner.call_args.args[1], "sample-sensitive-input")

    def test_browser_type_accepts_legacy_focused_input_schema(self):
        with mock.patch.object(m, "run_local_command_with_stdin", return_value={
            "host": m.CONTROLLER_BACKEND_HOST,
            "exit_code": 0,
            "stdout": '{"typed":true,"submitted":true,"mode":"focused"}',
            "stderr": "",
            "duration_ms": 1,
        }) as runner:
            result = m.execute_tool(
                "browser_type",
                {"text": "sample-sensitive-input", "press_enter": True},
            )
        self.assertTrue(result["ok"])
        runner.assert_called_once()
        argv, stdin_text = runner.call_args.args[:2]
        self.assertIn("process.stdin", " ".join(argv))
        self.assertEqual(stdin_text, "sample-sensitive-input")
        self.assertNotIn("sample-sensitive-input", " ".join(argv))

    def test_linux_service_status_is_fail_closed_and_checks_user_scope(self):
        command = m.service_command(
            "linux",
            "shopvivaliz-chatgpt-continuity.service",
            "status",
            "ubuntu",
        )
        self.assertIn("LoadState", command)
        self.assertIn("runuser -u ubuntu", command)
        self.assertIn("XDG_RUNTIME_DIR=/run/user/$uid", command)
        self.assertIn("systemctl --user", command)
        self.assertIn("service_not_found", command)
        self.assertIn("exit 4", command)
        self.assertNotIn("status shopvivaliz-chatgpt-continuity.service || true", command)

    def test_backend_config_declares_canonical_user_service_owner(self):
        self.assertEqual(m.HOSTS["always-free-arm-1787907847-26"]["service_user_owner"], "ubuntu")

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

    def test_audit_records_ownership_and_result_metadata_without_content(self):
        args = {
            "command": "printf secret-payload",
            "prompt": "private prompt body",
            "owner_kind": "foreground",
            "owner_id": "turn-1",
            "runtime_lease_id": "runtime-lease-1",
            "runtime_fencing_token": 4,
            "checkpoint_version": 7,
        }
        output = {
            "task_id": "durable-1",
            "queue_position": 9,
            "foreground_duration_ms": 41,
            "e2e_evidence_type": "real_assistant_response",
        }
        aid = m.audit("task_submit", "always-free-arm-1787907847-26", args, True, "ok", output=output)
        with m.db_conn() as db:
            raw = db.execute("SELECT args_json FROM audit WHERE id=?", (aid,)).fetchone()[0]
        payload = json.loads(raw)
        self.assertEqual(payload["owner_kind"], "foreground")
        self.assertEqual(payload["owner_id"], "turn-1")
        self.assertEqual(payload["runtime_lease_id"], "runtime-lease-1")
        self.assertEqual(payload["runtime_fencing_token"], 4)
        self.assertEqual(payload["checkpoint_version"], 7)
        self.assertEqual(payload["durable_execution_id"], "durable-1")
        self.assertEqual(payload["queue_position"], 9)
        self.assertEqual(payload["foreground_duration_ms"], 41)
        self.assertEqual(payload["e2e_evidence_type"], "real_assistant_response")
        self.assertNotIn("private prompt body", raw)
        self.assertNotIn("secret-payload", raw)

    def test_controller_promotion_and_continuity_restart_require_runtime_lock_when_enabled(self):
        old = os.environ.get("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF")
        os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = "1"
        try:
            with self.assertRaisesRegex(ValueError, "runtime_lock_required"):
                m.execute_tool("controller_promote", {"expected_sha": "a" * 40, "timeout": 120})
            with self.assertRaisesRegex(ValueError, "runtime_lock_required"):
                m.execute_tool("service_action", {
                    "host": "always-free-arm-1787907847-26",
                    "service": "shopvivaliz-chatgpt-continuity.service",
                    "action": "restart",
                    "timeout": 20,
                })
        finally:
            if old is None: os.environ.pop("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", None)
            else: os.environ["SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"] = old

    def test_audit_does_not_store_raw_command(self):
        raw = "echo super-sensitive-command-value"
        aid = m.audit("admin_command_run", "shopvivaliz-free-a1", {"command": raw}, True, "ok")
        with m.db_conn() as db:
            row = db.execute("SELECT args_json FROM audit WHERE id=?", (aid,)).fetchone()
        payload = json.loads(row["args_json"])
        self.assertNotIn("command", payload)
        self.assertIn("command_sha256", payload)
        self.assertNotIn(raw, row["args_json"])

    def test_run_host_command_cleans_isolated_scope_after_success(self):
        with mock.patch.object(m, "isolated_invocation", return_value=["bash", "-lc", "printf ok"]), \
             mock.patch.object(m, "_cleanup_isolated_scope") as cleanup:
            result = m.run_host_command("always-free-arm-1787907847-26", "printf ok", timeout=5)
        self.assertEqual(result["exit_code"], 0)
        cleanup.assert_called_once()

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

    def test_backend_reserves_second_durable_slot_for_control_work(self):
        first = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26",
            "command": "sleep 30",
            "timeout": 60,
        })
        second = m.execute_tool("task_submit", {
            "host": "always-free-arm-1787907847-26",
            "command": "printf diagnostic",
            "timeout": 30,
        })
        with m.db_conn() as db:
            db.execute(
                "UPDATE tasks SET state='running',execution_unit=?,started_at=?,execution_started_at=? WHERE id=?",
                (m.task_unit_name(first["task_id"]), m.now(), m.now(), first["task_id"]),
            )
        launched = []
        old_reconcile = m.reconcile_tasks
        old_launch = m.launch_task_service
        m.reconcile_tasks = lambda: []
        m.launch_task_service = lambda task_id, timeout: launched.append(task_id)
        m.STOP_EVENT.clear()
        worker = threading.Thread(target=m.task_worker, daemon=True)
        worker.start()
        deadline = time.time() + 2
        while second["task_id"] not in launched and time.time() < deadline:
            time.sleep(0.05)
        m.STOP_EVENT.set()
        worker.join(timeout=2)
        m.reconcile_tasks = old_reconcile
        m.launch_task_service = old_launch
        self.assertIn(second["task_id"], launched)

    def test_backend_durable_concurrency_is_bounded(self):
        self.assertEqual(m.HOST_DURABLE_LIMITS["always-free-arm-1787907847-26"], 2)
        self.assertEqual(m.HOST_DURABLE_LIMITS["shopvivaliz-free-a1"], 2)
        self.assertEqual(m.HOST_DURABLE_LIMITS["Fred-Win"], 1)
        self.assertEqual(m.HOST_DURABLE_LIMITS["KOCEPSV"], 1)

    def test_control_plane_and_agent_units_have_pressure_guardrails(self):
        controller = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-mcp.service").read_text(encoding="utf-8")
        claude = (ROOT / "deploy" / "systemd" / "shopvivaliz-claude-remote-control.service").read_text(encoding="utf-8")
        browser = (ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service").read_text(encoding="utf-8")
        chatgpt = (ROOT / "ops" / "systemd" / "shopvivaliz-chatgpt-browser.service").read_text(encoding="utf-8")
        for needle in ("CPUWeight=1000", "IOWeight=1000", "Nice=-5", "TasksMax=512"):
            self.assertIn(needle, controller)
        for needle in ("--capacity 1", "CPUQuota=100%", "MemoryHigh=2G", "MemoryMax=3G", "TasksMax=128"):
            self.assertIn(needle, claude)
        for needle in ("CPUQuota=100%", "MemoryHigh=2G", "MemoryMax=3G", "TasksMax=256"):
            self.assertIn(needle, browser)
        for needle in ("CPUQuota=120%", "MemoryHigh=3G", "MemoryMax=4G", "TasksMax=512"):
            self.assertIn(needle, chatgpt)

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

    def test_local_target_has_root_home_environment(self):
        inv = m.remote_invocation("always-free-arm-1787907847-26", "printf test")
        self.assertEqual(inv[:4], ["/usr/bin/env", "HOME=/root", "USER=root", "LOGNAME=root"])
        self.assertEqual(inv[4:6], ["bash", "-lc"])

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


    def test_reverse_ssh_transport_errors_are_narrowly_classified(self):
        self.assertTrue(m.is_recoverable_reverse_ssh_error(
            "Fred-Win",
            "Connection timed out during banner exchange\r\nConnection to 127.0.0.1 port 2222 timed out",
        ))
        self.assertTrue(m.is_recoverable_reverse_ssh_error(
            "KOCEPSV",
            "kex_exchange_identification: read: Connection reset by peer",
        ))
        self.assertFalse(m.is_recoverable_reverse_ssh_error(
            "Fred-Win",
            "Permission denied (publickey).",
        ))
        self.assertFalse(m.is_recoverable_reverse_ssh_error(
            "shopvivaliz-free-a1",
            "Connection timed out during banner exchange",
        ))

    def test_reverse_ssh_listener_pid_accepts_only_sshd_listener(self):
        sample = mock.Mock(
            returncode=0,
            stdout='LISTEN 0 128 127.0.0.1:2222 0.0.0.0:* users:(("sshd",pid=4321,fd=8))\n',
        )
        with (
            mock.patch.object(m.subprocess, "run", return_value=sample),
            mock.patch.object(m.Path, "read_text", return_value="sshd\n"),
        ):
            self.assertEqual(4321, m.reverse_ssh_listener_pid(2222))

        with (
            mock.patch.object(m.subprocess, "run", return_value=sample),
            mock.patch.object(m.Path, "read_text", return_value="python\n"),
        ):
            self.assertIsNone(m.reverse_ssh_listener_pid(2222))

    def test_recover_reverse_ssh_transport_recycles_listener_until_new_pid(self):
        pids = iter([4321, 4321, 9876])
        with (
            mock.patch.object(m, "reverse_ssh_listener_pid", side_effect=lambda port: next(pids)),
            mock.patch.object(m.os, "kill") as kill,
            mock.patch.object(m.time, "sleep"),
            mock.patch.object(m.time, "monotonic", side_effect=[10.0, 10.1, 10.2]),
        ):
            self.assertTrue(m.recover_reverse_ssh_transport("Fred-Win"))
        kill.assert_called_once_with(4321, m.signal.SIGTERM)

    def test_safe_reverse_ssh_retry_rejects_ambiguous_connection_reset(self):
        self.assertTrue(m.is_safe_pre_execution_reverse_ssh_error(
            "KOCEPSV",
            "Connection timed out during banner exchange",
        ))
        self.assertTrue(m.is_safe_pre_execution_reverse_ssh_error(
            "KOCEPSV",
            "kex_exchange_identification: read: Connection reset by peer",
        ))
        self.assertFalse(m.is_safe_pre_execution_reverse_ssh_error(
            "KOCEPSV",
            "Connection reset by peer",
        ))
        self.assertFalse(m.is_safe_pre_execution_reverse_ssh_error(
            "KOCEPSV",
            "Connection closed by remote host",
        ))

    def test_read_only_host_tools_disable_transport_recovery(self):
        cases = [
            ("host_health", {"host": "Fred-Win"}),
            ("processes_list", {"host": "Fred-Win"}),
            ("service_status", {"host": "Fred-Win", "service": "RustDesk"}),
            ("file_read", {"host": "Fred-Win", "path": r"C:\\Windows\\win.ini"}),
            ("file_list", {"host": "Fred-Win", "path": r"C:\\Windows"}),
            ("logs_tail", {"host": "Fred-Win", "path": r"C:\\Windows\\WindowsUpdate.log"}),
        ]
        for name, args in cases:
            with self.subTest(tool=name), mock.patch.object(
                m,
                "run_host_command",
                return_value={"host": "Fred-Win", "exit_code": 0, "stdout": "", "stderr": "", "duration_ms": 1},
            ) as run:
                result = m.execute_tool(name, args)
                self.assertTrue(result["ok"])
                self.assertFalse(run.call_args.kwargs.get("recover_transport", True))

    def test_run_host_command_can_probe_without_mutating_reverse_ssh_transport(self):
        class FakeProc:
            returncode = 255
            pid = 1001

            def communicate(self, timeout=None):
                return b"", b"Connection timed out during banner exchange"

            def poll(self):
                return self.returncode

        self.assertIn("recover_transport", m.run_host_command.__code__.co_varnames)
        with (
            mock.patch.object(m, "isolated_invocation", return_value=["ssh"]),
            mock.patch.object(m, "remote_invocation", return_value=["ssh"]),
            mock.patch.object(m.subprocess, "Popen", return_value=FakeProc()),
            mock.patch.object(m, "_cleanup_isolated_scope"),
            mock.patch.object(m, "recover_reverse_ssh_transport") as recover,
        ):
            result = m.run_host_command(
                "Fred-Win",
                "Get-Date",
                timeout=10,
                recover_transport=False,
            )
        self.assertEqual(255, result["exit_code"])
        self.assertTrue(result["transport_recovery_available"])
        recover.assert_not_called()

    def test_run_host_command_retries_once_after_reverse_ssh_recovery(self):
        class FakeProc:
            def __init__(self, rc, stdout, stderr):
                self.returncode = rc
                self._stdout = stdout
                self._stderr = stderr
                self.pid = 1000 + rc

            def communicate(self, timeout=None):
                return self._stdout, self._stderr

            def poll(self):
                return self.returncode

        first = FakeProc(255, b"", b"Connection timed out during banner exchange")
        second = FakeProc(0, b"ok", b"")
        with (
            mock.patch.object(m, "isolated_invocation", return_value=["ssh"]),
            mock.patch.object(m, "remote_invocation", return_value=["ssh"]),
            mock.patch.object(m.subprocess, "Popen", side_effect=[first, second]) as popen,
            mock.patch.object(m, "_cleanup_isolated_scope"),
            mock.patch.object(m, "recover_reverse_ssh_transport", return_value=True) as recover,
        ):
            result = m.run_host_command("Fred-Win", "Get-Date", timeout=10)
        self.assertEqual(0, result["exit_code"])
        self.assertEqual("ok", result["stdout"])
        self.assertTrue(result["transport_recovered"])
        self.assertEqual(2, popen.call_count)
        recover.assert_called_once_with("Fred-Win")


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

    def test_controller_install_refreshes_active_browser_mcp_after_base_tool_changes(self):
        setup = (ROOT / "scripts" / "setup-remote-control-access.sh").read_text(encoding="utf-8")
        self.assertIn('BROWSER_SERVICE="shopvivaliz-remote-control-browser-mcp.service"', setup)
        self.assertIn('systemctl try-restart "$BROWSER_SERVICE"', setup)
        self.assertIn("http://127.0.0.1:5581/health", setup)
        self.assertIn("REMOTE_CONTROL_BROWSER_DEPENDENT_REFRESH=PASS", setup)

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

    def test_bootstrap_tracks_validates_and_installs_browser_mcp_from_same_checkout(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        for path in (
            "remote-control-browser-mcp/**",
            "scripts/setup-remote-control-browser-mcp.sh",
            "scripts/chatgpt-continuity/chatgpt-browser-infer.mjs",
            "deploy/systemd/shopvivaliz-remote-control-browser-mcp.service",
            "deploy/systemd/shopvivaliz-browser-atendimento-mcp.service",
            "deploy/systemd/shopvivaliz-browser-dev-mcp.service",
            "scripts/shopvivaliz-native-desktop-bridge.ps1",
            "tests/remote-control-browser-mcp-test.py",
        ):
            self.assertIn(path, text)
        self.assertIn(
            "python3 -m py_compile remote-control-mcp/server.py remote-control-browser-mcp/server.py",
            text,
        )
        self.assertIn("python3 tests/remote-control-browser-mcp-test.py", text)
        self.assertIn("bash -n scripts/setup-remote-control-browser-mcp.sh", text)
        self.assertIn("node --check scripts/chatgpt-continuity/chatgpt-browser-infer.mjs", text)
        self.assertIn(
            "sudo -n bash scripts/setup-remote-control-browser-mcp.sh remote-control-browser-mcp/server.py deploy/systemd/shopvivaliz-remote-control-browser-mcp.service",
            text,
        )

    def test_bootstrap_stages_native_fred_desktop_bridge_from_canonical_checkout(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("- name: Stage native Fred-Win desktop bridge", text)
        start = text.index("- name: Stage native Fred-Win desktop bridge")
        block = text[start:]
        self.assertIn("scripts/shopvivaliz-native-desktop-bridge.ps1", block)
        self.assertIn("/var/lib/shopvivaliz-remote-control/id_ed25519", block)
        self.assertIn("127.0.0.1", block)
        self.assertIn("2222", block)
        self.assertIn("Parser]::ParseFile", block)
        self.assertIn("REMOTE_CONTROL_FRED_NATIVE_DESKTOP_BRIDGE=PASS", block)
        self.assertNotIn("git show origin/main:scripts/shopvivaliz-native-desktop-bridge.ps1", block)

    def test_bootstrap_runs_on_controller_backend(self):
        text = (ROOT / ".github" / "workflows" / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]", text)
        self.assertIn("sudo -n bash scripts/setup-remote-control-access.sh install-controller remote-control-mcp/server.py deploy/systemd/shopvivaliz-remote-control-mcp.service", text)
        self.assertNotIn("ubuntu@10.0.1.38)", text)
        self.assertIn("ubuntu@10.0.1.112", text)

    def test_oci_bastion_bootstrap_copies_and_passes_canonical_controller_unit(self):
        text = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn('"${BACKEND_SCP[@]}" -r remote-control-mcp/server.py scripts/setup-remote-control-access.sh deploy/systemd/shopvivaliz-remote-control-mcp.service scripts/agent_task_state.py scripts/continuity ubuntu@127.0.0.1:/tmp/', text)
        self.assertIn('install-controller /tmp/server.py /tmp/shopvivaliz-remote-control-mcp.service /tmp/agent_task_state.py /tmp/continuity', text)

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
        self.assertNotIn('--no-create-session-in-dir', unit_text)
        self.assertIn('Restart=always', unit_text)
        self.assertIn('StandardOutput=journal', unit_text)
        self.assertIn('StandardError=journal', unit_text)
        self.assertIn('service_session_recovery_disabled', setup_text)
        self.assertIn('systemctl show "$SERVICE" -p ExecStart --value', setup_text)
        self.assertIn('service_previously_installed=false', setup_text)
        self.assertIn('CLAUDE_REMOTE_CONTROL_CONSENT=SKIP reason=existing_service', setup_text)
        self.assertIn('if [[ "$service_previously_installed" = true ]]; then', setup_text)
        self.assertIn('CLAUDE_REMOTE_CONTROL_ELIGIBILITY=remote_control_help', setup_text)
        self.assertIn('remote_control_help_missing', setup_text)
        self.assertIn('help_rc', setup_text)
        self.assertIn("grep -Fq -- '--spawn <mode>'", setup_text)

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
            "ops/systemd/shopvivaliz-dev-browser.service",
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
        self.assertIn('trap \'rm -f "$tmp" "$help_out"\' RETURN', probe)
        self.assertIn('help_out="$(mktemp)" || die claude_help_tmpfile_failed 34', probe)
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

    def test_durable_health_is_degraded_when_indeterminate_tasks_need_review(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "indeterminate")
        summary = m.durable_health_summary()
        self.assertEqual(summary["indeterminate"], 1)
        self.assertTrue(summary["degraded"])

    def test_api_processes_indeterminate_by_analysis_without_reexecution(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        result_dir = m.ensure_task_result_dir(task_id)
        (result_dir / "stdout.log").write_text("CHECK PASS\n", encoding="utf-8")
        (result_dir / "stderr.log").write_text("", encoding="utf-8")
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "indeterminate")

        with (
            mock.patch.object(m, "run_task_entrypoint") as runner,
            mock.patch.object(m.subprocess, "Popen") as spawn,
            mock.patch.object(m, "recover_reverse_ssh_transport") as recover,
        ):
            processed = m.execute_tool("task_process_indeterminate", {"limit": 10})

        self.assertEqual(processed["processed"], 1)
        self.assertEqual(processed["classifications"]["positive_evidence"], 1)
        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["state"], "indeterminate")
        self.assertEqual(status["analysis_status"], "processed")
        self.assertEqual(status["analysis_result"], "positive_evidence")
        self.assertEqual(status["analysis_intervention_required"], 0)
        self.assertIsNotNone(status["analyzed_at"])
        runner.assert_not_called()
        spawn.assert_not_called()
        recover.assert_not_called()

    def test_durable_health_separates_processed_indeterminate_from_pending_review(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        result_dir = m.ensure_task_result_dir(task_id)
        (result_dir / "stdout.log").write_text("CHECK PASS\n", encoding="utf-8")
        (result_dir / "stderr.log").write_text("", encoding="utf-8")
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "indeterminate")

        before = m.durable_health_summary()
        self.assertEqual(before["indeterminate"], 1)
        self.assertEqual(before["indeterminate_pending_analysis"], 1)
        self.assertEqual(before["indeterminate_processed"], 0)
        self.assertTrue(before["degraded"])

        processed = m.execute_tool("task_process_indeterminate", {"limit": 10})
        self.assertEqual(processed["processed"], 1)

        after = m.durable_health_summary()
        self.assertEqual(after["indeterminate"], 1)
        self.assertEqual(after["indeterminate_pending_analysis"], 0)
        self.assertEqual(after["indeterminate_processed"], 1)
        self.assertFalse(after["degraded"])

    def test_worker_processes_newly_indeterminate_tasks_analytically(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        result_dir = m.ensure_task_result_dir(task_id)
        (result_dir / "stdout.log").write_text("CHECK PASS\n", encoding="utf-8")
        (result_dir / "stderr.log").write_text("", encoding="utf-8")

        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            worker = threading.Thread(target=m.task_worker, daemon=True)
            worker.start()
            deadline = time.monotonic() + 1.5
            status = m.execute_tool("task_status", {"task_id": task_id})
            while time.monotonic() < deadline and status.get("analysis_status") != "processed":
                time.sleep(0.03)
                status = m.execute_tool("task_status", {"task_id": task_id})
            m.STOP_EVENT.set()
            worker.join(timeout=1)

        self.assertEqual(status["state"], "indeterminate")
        self.assertEqual(status["analysis_status"], "processed")
        self.assertEqual(status["analysis_result"], "positive_evidence")

    def test_indeterminate_analysis_treats_tap_not_ok_as_negative_evidence(self):
        task_id = self.submit()["task_id"]
        self.claim(task_id, state="running", started=True)
        result_dir = m.ensure_task_result_dir(task_id)
        (result_dir / "stdout.log").write_text("not ok 1 - checkout\n", encoding="utf-8")
        (result_dir / "stderr.log").write_text("", encoding="utf-8")
        with mock.patch.object(m, "systemd_unit_state", return_value="inactive"):
            self.assertEqual(m.reconcile_task(m.load_task(task_id)), "indeterminate")

        processed = m.execute_tool("task_process_indeterminate", {"limit": 10})

        self.assertEqual(processed["processed"], 1)
        self.assertEqual(processed["classifications"]["negative_evidence"], 1)
        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["analysis_result"], "negative_evidence")
        evidence = json.loads(status["analysis_evidence_json"])
        self.assertEqual(evidence["negative_markers"], 1)
        self.assertEqual(evidence["positive_markers"], 0)

    def test_task_process_indeterminate_is_an_api_tool_with_no_external_intervention_contract(self):
        specs = {item["name"]: item for item in m.tool_specs()}
        self.assertIn("task_process_indeterminate", specs)
        spec = specs["task_process_indeterminate"]
        self.assertFalse(spec["annotations"]["readOnlyHint"])
        self.assertFalse(spec["annotations"]["destructiveHint"])
        self.assertEqual(
            "integer",
            spec["inputSchema"]["properties"]["limit"]["type"],
        )

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

    def test_task_wait_detaches_immediately_when_same_host_is_backlogged(self):
        first_id = self.submit(command="sleep 30")["task_id"]
        second_id = self.submit(command="sleep 31")["task_id"]
        self.claim(first_id, state="running", started=True)
        self.claim(second_id, state="running", started=True)
        queued_id = self.submit(command="printf third")["task_id"]
        started = time.monotonic()
        result = m.execute_tool("task_wait", {"task_id": queued_id, "wait_seconds": 5})
        elapsed = time.monotonic() - started
        self.assertEqual(result["state"], "queued")
        self.assertTrue(result["detached"])
        self.assertTrue(result["blocked_by_active"])
        self.assertEqual(result["active_for_host"], 2)
        self.assertEqual(result["host_concurrency_limit"], 2)
        self.assertEqual(result["queue_position"], 1)
        self.assertLess(elapsed, 0.5)

    def test_worker_allows_one_durable_task_per_host_in_parallel(self):
        active_id = self.submit(command="sleep 30")["task_id"]
        self.claim(active_id, state="running", started=True)
        site = m.execute_tool("task_submit", {
            "host": "shopvivaliz-free-a1",
            "command": "printf site",
            "timeout": 30,
        })
        with mock.patch.object(m, "systemd_unit_state", return_value="active"), \
             mock.patch.object(m, "launch_task_service") as launch:
            worker = threading.Thread(target=m.task_worker, daemon=True)
            worker.start()
            deadline = time.monotonic() + 1
            while time.monotonic() < deadline and not launch.called:
                time.sleep(0.02)
            m.STOP_EVENT.set()
            worker.join(timeout=2)
        self.assertTrue(launch.called)
        launched_ids = [call.args[0] for call in launch.call_args_list]
        self.assertIn(site["task_id"], launched_ids)

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

    def test_runner_retries_once_after_reverse_ssh_recovery(self):
        task_id = m.execute_tool("task_submit", {
            "host": "KOCEPSV",
            "command": "Write-Output durable-recovered",
            "timeout": 30,
        })["task_id"]
        self.claim(task_id)
        attempts = {"count": 0}

        class FakeProc:
            def __init__(self, rc):
                self.returncode = rc
                self.pid = 4000 + attempts["count"]

            def poll(self):
                return self.returncode

        def fake_popen(_argv, stdout=None, stderr=None, **_kwargs):
            attempts["count"] += 1
            if attempts["count"] == 1:
                stderr.write(b"Connection timed out during banner exchange\n")
                return FakeProc(255)
            stdout.write(b"durable-recovered\n")
            return FakeProc(0)

        with (
            mock.patch.object(m, "remote_invocation", return_value=["ssh"]),
            mock.patch.object(m.subprocess, "Popen", side_effect=fake_popen) as popen,
            mock.patch.object(m, "recover_reverse_ssh_transport", return_value=True) as recover,
        ):
            self.assertEqual(m.run_task_entrypoint(task_id), 0)

        status = m.execute_tool("task_status", {"task_id": task_id})
        self.assertEqual(status["state"], "succeeded")
        self.assertIn("durable-recovered", status["stdout"])
        self.assertEqual(popen.call_count, 2)
        recover.assert_called_once_with("KOCEPSV")

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
