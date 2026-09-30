from __future__ import annotations

import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
import sys
sys.path.insert(0, str(SCRIPTS))

import agent_task_state as task_state
import task_continuation_watchdog as watchdog


def load_dispatcher():
    path = SCRIPTS / "chatgpt_continuity_nudge_dispatcher.py"
    spec = importlib.util.spec_from_file_location("chatgpt_continuity_nudge_dispatcher_backend_runtime_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load dispatcher")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ChatgptContinuityBackendRuntimeTests(unittest.TestCase):
    def test_dispatcher_reads_protected_token_file_when_env_value_is_absent(self) -> None:
        dispatcher = load_dispatcher()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            token_file = root / "bridge.token"
            token_file.write_text("file-token-1234567890\n", encoding="utf-8")
            token_file.chmod(0o600)
            original_state_runtime = task_state.RUNTIME_DIR
            original_watchdog_runtime = watchdog.RUNTIME_DIR
            task_state.RUNTIME_DIR = root
            watchdog.RUNTIME_DIR = root
            try:
                task_state.start_task("task-1", "goal", "gpt")
                task_state.record_progress("task-1", next_action="continue safely")
                state_path = root / "task-1.json"
                payload = json.loads(state_path.read_text(encoding="utf-8"))
                payload["updated_at"] = "2020-01-01T00:00:00Z"
                state_path.write_text(json.dumps(payload), encoding="utf-8")
                watchdog.run_once(stale_seconds=1, runtime_dir=root)
            finally:
                task_state.RUNTIME_DIR = original_state_runtime
                watchdog.RUNTIME_DIR = original_watchdog_runtime
            calls: list[dict] = []

            def fake_enqueue(**kwargs):
                calls.append(kwargs)
                return {"ok": True, "http_status": 200, "body": {"status": "OK"}}

            with patch.dict(
                os.environ,
                {
                    "CHATGPT_CONTINUITY_BRIDGE_TOKEN": "",
                    "CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE": str(token_file),
                },
                clear=False,
            ):
                result = dispatcher.run_once(
                    runtime_dir=root,
                    bridge_url="https://example.invalid/bridge.php",
                    token="",
                    enqueue=fake_enqueue,
                )

            self.assertEqual(result["dispatched"], 1)
            self.assertEqual(result["skipped_no_token"], 0)
            self.assertEqual(calls[0]["token"], "file-token-1234567890")
            self.assertNotIn("file-token-1234567890", json.dumps(result))

    def test_backend_installer_is_vm_native_and_attaches_to_canonical_cdp(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        self.assertTrue(installer.is_file(), "backend continuity installer must exist")
        body = installer.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-chatgpt-continuity.service", body)
        self.assertIn("http://127.0.0.1:9555", body)
        self.assertIn("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", body)
        self.assertIn("systemctl --user enable --now", body)
        self.assertNotIn("C:\\ShopVivaliz", body)

    def test_backend_installer_restarts_only_when_runtime_changes(self) -> None:
        installer = ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh"
        body = installer.read_text(encoding="utf-8")
        self.assertIn("install_if_changed()", body)
        self.assertIn("worker_changed=false", body)
        self.assertIn("tunnel_unit_changed=false", body)
        self.assertIn("continuity_unit_changed=false", body)
        self.assertIn('if [[ "$tunnel_unit_changed" = true ]]', body)
        self.assertIn('if [[ "$worker_changed" = true || "$continuity_unit_changed" = true ]]', body)
        self.assertNotIn('systemctl --user restart "$tunnel_unit"\n', body)
        self.assertNotIn('systemctl --user restart "$unit"\n', body)

    def test_php_bridge_supports_file_backed_secret(self) -> None:
        bridge = (ROOT / "api" / "chatgpt-continuity" / "bridge.php").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE", bridge)
        self.assertIn("bridge.token", bridge)

    def test_docs_pin_chatgpt_session_reentry_to_backend_vm(self) -> None:
        docs = (ROOT / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        rules = (ROOT / "docs" / "knowledge" / "agent-rules.md").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_SESSION_REENTRY_V10", docs)
        self.assertIn("127.0.0.1:9555", docs)
        self.assertIn("always-free-arm-1787907847-26", rules)
        self.assertIn("shopvivaliz-chatgpt-continuity.service", rules)


    def test_runtime_transport_bypasses_public_cloudflare_path(self) -> None:
        installer = (ROOT / "scripts" / "install-chatgpt-continuity-backend-bridge.sh").read_text(encoding="utf-8")
        dispatcher = (ROOT / "scripts" / "chatgpt_continuity_nudge_dispatcher.py").read_text(encoding="utf-8")
        worker = (ROOT / "scripts" / "chatgpt-continuity" / "chatgpt-continuity-bridge-worker.mjs").read_text(encoding="utf-8")
        self.assertIn("http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php", installer)
        self.assertIn("shopvivaliz-chatgpt-continuity-a1-tunnel.service", installer)
        self.assertIn("/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor", installer)
        self.assertIn("-L 127.0.0.1:18081:127.0.0.1:8080", installer)
        self.assertIn("ubuntu@10.0.1.112", installer)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER", installer)
        self.assertIn("http://127.0.0.1:8080/api/chatgpt-continuity/bridge.php", dispatcher)
        self.assertIn("Host", dispatcher)
        self.assertIn("http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php", worker)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER", worker)


if __name__ == "__main__":
    unittest.main()
