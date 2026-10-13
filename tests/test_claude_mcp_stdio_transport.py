"""Transport regressions; fault injection never calls the live account or hosts."""
from __future__ import annotations

import errno
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import socket
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import unittest
from unittest import mock
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("stdio_transport_under_test", ROOT / "scripts" / "claude-remote-control-mcp-stdio.py")
assert SPEC is not None and SPEC.loader is not None
adapter = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(adapter)


class Response(io.BytesIO):
    status = 200


class StdioTransportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.token_path = Path(self.temp.name) / "test-token"
        self.token_path.write_text("not-a-real-credential", encoding="utf-8")
        self.token_patch = mock.patch.object(adapter, "TOKEN_PATH", self.token_path)
        self.token_patch.start()
        self.addCleanup(self.token_patch.stop)
        self.addCleanup(self.temp.cleanup)
        self.calls = []

    @staticmethod
    def request(name="admin_command_run", **arguments):
        return {"jsonrpc": "2.0", "id": "transport-test-7", "method": "tools/call", "params": {"name": name, "arguments": {"host": "always-free-arm-1787907847-26", "command": "printf test", **arguments}}}

    def reply(self, request, timeout):
        payload = json.loads(request.data)
        self.calls.append((request.full_url, payload, timeout))
        name = payload["params"].get("name")
        if name == "task_submit":
            output = {"task_id": "test-durable-task", "state": "queued"}
        elif name == "task_status":
            output = {"task_id": payload["params"]["arguments"]["task_id"], "state": "running"}
        elif payload["method"] == "tools/list":
            return Response(json.dumps({"jsonrpc": "2.0", "id": payload["id"], "result": {"tools": [
                {"name": "task_wait"}, {"name": "foreground_handoff"},
                {"name": "foreground_renew"}, {"name": "foreground_release"},
            ]}}).encode())
        else:
            output = {"exit_code": 0, "stdout": "test"}
        return Response(json.dumps({"jsonrpc": "2.0", "id": payload["id"], "result": {"structuredContent": output, "content": [{"type": "text", "text": json.dumps(output)}], "isError": False}}).encode())

    def forward(self, payload):
        return adapter.forward(json.dumps(payload).encode())

    def test_long_admin_is_submitted_before_execution_and_returns_task_receipt(self):
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            result = self.forward(self.request(timeout=6))
        sent = self.calls[0][1]
        self.assertEqual(sent["params"]["name"], "task_submit")
        self.assertEqual(sent["params"]["arguments"]["timeout"], 6)
        self.assertEqual(sent["params"]["arguments"]["command"], "printf test")
        self.assertTrue(sent["params"]["arguments"]["request_id"])
        out = result["result"]["structuredContent"]
        self.assertEqual(out["execution_mode"], "durable")
        self.assertEqual(out["task_id"], "test-durable-task")
        self.assertEqual(out["requested_tool"], "admin_command_run")
        self.assertEqual(json.loads(result["result"]["content"][0]["text"]), out)
        self.assertEqual(result["id"], "transport-test-7")

    def test_short_admin_remains_inline_and_unchanged(self):
        payload = self.request(timeout=5)
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            result = self.forward(payload)
        self.assertEqual(self.calls[0][1], payload)
        self.assertEqual(result["result"]["structuredContent"]["exit_code"], 0)

    def test_default_admin_keeps_original_30_second_limit_when_promoted(self):
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            self.forward(self.request())
        self.assertEqual(self.calls[0][1]["params"]["name"], "task_submit")
        self.assertEqual(self.calls[0][1]["params"]["arguments"]["timeout"], 30)

    def test_explicit_durable_keeps_caller_idempotency_key(self):
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            self.forward(self.request(timeout=10, durable=True, request_id="caller-job-9"))
        sent = self.calls[0][1]["params"]
        self.assertEqual(sent["name"], "task_submit")
        self.assertEqual(sent["arguments"]["request_id"], "caller-job-9")
        self.assertNotIn("durable", sent["arguments"])

    def test_explicit_inline_long_request_is_rejected_without_side_effects(self):
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            result = self.forward(self.request(timeout=6, durable=False))
        self.assertEqual(self.calls, [])
        self.assertEqual(result["error"]["message"], "foreground_budget_exceeded_use_task_submit")

    def test_task_wait_is_detached_to_one_non_blocking_status_request(self):
        payload = self.request(name="task_wait", task_id="task-foreground-9", timeout=7200)
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            result = self.forward(payload)
        self.assertEqual(len(self.calls), 1)
        sent = self.calls[0][1]
        self.assertEqual(sent["id"], payload["id"])
        self.assertEqual(sent["params"], {"name": "task_status", "arguments": {"task_id": "task-foreground-9"}})
        output = result["result"]["structuredContent"]
        self.assertEqual(output["task_id"], "task-foreground-9")
        self.assertEqual(output["foreground_wait"]["requested_tool"], "task_wait")
        self.assertTrue(output["foreground_wait"]["foreground_wait_detached"])
        self.assertEqual(output["foreground_wait"]["reason"], "foreground_wait_forbidden")

    def test_tools_list_hides_task_wait_and_keeps_foreground_lease_tools(self):
        payload = {"jsonrpc": "2.0", "id": "tool-list-4", "method": "tools/list", "params": {}}
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            result = self.forward(payload)
        self.assertEqual(self.calls[0][1], payload)
        names = {tool["name"] for tool in result["result"]["tools"]}
        self.assertNotIn("task_wait", names)
        self.assertTrue({"foreground_handoff", "foreground_renew", "foreground_release"} <= names)

    def test_timeout_is_indeterminate_and_never_replayed_to_fallback(self):
        calls = []
        def transport(request, timeout):
            calls.append(request.full_url)
            if len(calls) == 1:
                raise TimeoutError("secret-must-not-leak")
            return self.reply(request, timeout)
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=transport):
            result = self.forward(self.request(timeout=10))
        self.assertEqual(len(calls), 1, "a lost response does not prove the command did not run")
        self.assertEqual(result["error"]["message"], "controller_response_indeterminate")
        self.assertFalse(result["error"]["data"]["retry_safe"])
        self.assertNotIn("secret-must-not-leak", json.dumps(result))

    def test_connection_reset_is_not_a_safe_replay(self):
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=ConnectionResetError("reset")) as call:
            result = self.forward(self.request(timeout=10))
        self.assertEqual(call.call_count, 1)
        self.assertEqual(result["error"]["message"], "controller_response_indeterminate")

    def test_urlerror_wrapped_timeout_is_not_replayed(self):
        error = urllib.error.URLError(TimeoutError("timeout"))
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=error) as call:
            result = self.forward(self.request(timeout=10))
        self.assertEqual(call.call_count, 1)
        self.assertEqual(result["error"]["message"], "controller_response_indeterminate")

    def test_confirmed_connection_refusal_still_uses_fallback(self):
        calls = []
        def transport(request, timeout):
            calls.append(request.full_url)
            if len(calls) == 1:
                raise urllib.error.URLError(ConnectionRefusedError(errno.ECONNREFUSED, "refused"))
            return self.reply(request, timeout)
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=transport):
            result = self.forward(self.request(timeout=5))
        self.assertEqual(calls, list(adapter.MCP_URLS))
        self.assertEqual(result["result"]["structuredContent"]["exit_code"], 0)

    def test_durable_uncertain_submission_returns_its_lookup_key_without_retry(self):
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=TimeoutError("timeout")) as call:
            result = self.forward(self.request(name="task_submit", timeout=60, request_id="known-job"))
        self.assertEqual(call.call_count, 1)
        self.assertEqual(result["error"]["data"]["request_id"], "known-job")

    def test_lost_response_after_effect_is_not_replayed_over_real_http(self):
        # Both endpoints are local fixtures. The first accepts and records the
        # request, then drops its response. A retry would duplicate that effect.
        effects = []
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass
            def do_POST(self):
                payload = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                effects.append(payload["id"])
                if self.server.drop_response:
                    self.connection.shutdown(socket.SHUT_RDWR)
                    self.connection.close()
                    return
                body = json.dumps({"jsonrpc": "2.0", "id": payload["id"], "result": {"structuredContent": {"exit_code": 0}, "isError": False}}).encode()
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
        servers = [ThreadingHTTPServer(("127.0.0.1", 0), Handler) for _ in range(2)]
        threads = []
        try:
            for index, server in enumerate(servers):
                server.drop_response = index == 0
                thread = threading.Thread(target=server.serve_forever, kwargs={"poll_interval": 0.01}, daemon=True)
                thread.start()
                threads.append(thread)
            urls = tuple("http://127.0.0.1:%s/mcp" % server.server_port for server in servers)
            with mock.patch.object(adapter, "MCP_URLS", urls):
                result = self.forward(self.request(timeout=10))
            self.assertEqual(effects, ["transport-test-7"])
            self.assertEqual(result["error"]["message"], "controller_response_indeterminate")
        finally:
            for server in servers:
                server.shutdown()
                server.server_close()
            for thread in threads:
                thread.join(timeout=2)

    def test_http_failure_is_not_replayed(self):
        error = urllib.error.HTTPError(adapter.MCP_URLS[0], 503, "unavailable", {}, io.BytesIO(b"hidden-body"))
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=error) as call:
            result = self.forward(self.request(timeout=10))
        self.assertEqual(call.call_count, 1)
        self.assertEqual(result["error"]["message"], "controller_http_503")
        self.assertNotIn("hidden-body", json.dumps(result))

    def test_missing_token_is_safe_json_error_not_adapter_crash(self):
        self.token_path.unlink()
        with mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
            result = self.forward(self.request(timeout=10))
        self.assertEqual(result["error"]["message"], "controller_token_unavailable")
        self.assertEqual(self.calls, [])

    def test_durable_timeout_uses_protected_runtime_limit(self):
        self.token_path.parent.mkdir(parents=True, exist_ok=True)
        service_env = Path(self.temp.name) / "service.env"
        service_env.write_text(
            "SHOPVIVALIZ_REMOTE_MCP_TOKEN=not-a-real-credential\n"
            "SHOPVIVALIZ_REMOTE_MCP_MAX_DURABLE_TIMEOUT=7200\n",
            encoding="utf-8",
        )
        with (
            mock.patch.object(adapter, "SERVICE_ENV_PATH", service_env, create=True),
            mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply),
        ):
            result = self.forward(self.request(timeout=7200, durable=True))
        self.assertNotIn("error", result)
        sent = self.calls[0][1]
        self.assertEqual(sent["params"]["name"], "task_submit")
        self.assertEqual(sent["params"]["arguments"]["timeout"], 7200)

    def test_durable_timeout_above_protected_runtime_limit_is_rejected(self):
        service_env = Path(self.temp.name) / "service.env"
        service_env.write_text(
            "SHOPVIVALIZ_REMOTE_MCP_MAX_DURABLE_TIMEOUT=7200\n",
            encoding="utf-8",
        )
        with (
            mock.patch.object(adapter, "SERVICE_ENV_PATH", service_env, create=True),
            mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply),
        ):
            result = self.forward(self.request(timeout=7201, durable=True))
        self.assertEqual(self.calls, [])
        self.assertEqual(result["error"]["message"], "invalid_timeout")

    def test_invalid_request_or_timeout_never_reaches_controller(self):
        for payload in ([], self.request(timeout=0), self.request(timeout=86401), self.request(timeout=True)):
            with self.subTest(payload=payload), mock.patch.object(adapter.urllib.request, "urlopen", side_effect=self.reply):
                result = self.forward(payload)
                self.assertIn("error", result)
        self.assertEqual(self.calls, [])


if __name__ == "__main__":
    unittest.main()
