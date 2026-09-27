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
            request = {
                "preferred_executor": "chatgpt_common",
                "status": "queued",
                "fingerprint": "fp-1",
                "task_id": "task-1",
                "repository": "Vivaliz-site/site-shopvivaliz",
            }
            (root / "_resume-requests.jsonl").write_text(json.dumps(request) + "\n", encoding="utf-8")
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


if __name__ == "__main__":
    unittest.main()
