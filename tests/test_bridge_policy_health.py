"""Prove intentional paid-provider denial is healthy for deployments."""
from __future__ import annotations

from io import BytesIO
from pathlib import Path
from urllib.error import HTTPError
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / "ops/ai-squad/bridge-health-policy.py"
SPEC = importlib.util.spec_from_file_location("bridge_policy_health", FILE)
assert SPEC is not None and SPEC.loader is not None
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class FakeResponse:
    def __init__(self, data, status=200):
        self.data = BytesIO(json.dumps(data).encode())
        self.status = status

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self, amount):
        return self.data.read(amount)


class FakeOpener:
    def __init__(self, bridge, payload, post_status=403):
        self.bridge = bridge
        self.payload = payload
        self.post_status = post_status
        self.calls = []

    def open(self, request, timeout):
        self.calls.append((request.get_method(), request.full_url, timeout))
        assert request.full_url.startswith("http://127.0.0.1:")
        if request.get_method() == "GET":
            return FakeResponse(self.payload)
        if self.post_status != 200:
            raise HTTPError(request.full_url, self.post_status, "denied", {}, None)
        return FakeResponse({"ok": True})


def disabled(provider):
    return {
        "endpoint": "ai-squad-" + provider + "-bridge",
        "ok": False,
        "auth_mode": "disabled",
        "policy": "manual_approval_required",
    }


class BridgePolicyHealthTests(unittest.TestCase):
    def test_disabled_bridge_denies_post(self):
        for provider in ("codex", "claude"):
            with self.subTest(provider=provider):
                opener = FakeOpener(provider, disabled(provider))
                self.assertEqual(module.check_bridge(provider, opener=opener), "disabled")
                self.assertEqual([c[0] for c in opener.calls], ["GET", "POST"])

    def test_disabled_without_real_403_is_invalid(self):
        for code in (200, 400, 429, 500):
            opener = FakeOpener("codex", disabled("codex"), post_status=code)
            self.assertEqual(module.check_bridge("codex", opener=opener), "invalid")

    def test_legacy_ready_codex_requires_live_search(self):
        data = {"endpoint": "ai-squad-codex-bridge", "ok": True, "web_search_mode": "live"}
        opener = FakeOpener("codex", data)
        self.assertEqual(module.check_bridge("codex", opener=opener), "ready")
        self.assertEqual([c[0] for c in opener.calls], ["GET"])
        data["web_search_mode"] = "cached"
        self.assertEqual(module.check_bridge("codex", opener=FakeOpener("codex", data)), "invalid")

    def test_legacy_ready_claude_requires_auth(self):
        data = {"endpoint": "ai-squad-claude-bridge", "ok": True, "authenticated": True}
        self.assertEqual(module.check_bridge("claude", opener=FakeOpener("claude", data)), "ready")
        data["authenticated"] = False
        self.assertEqual(module.check_bridge("claude", opener=FakeOpener("claude", data)), "invalid")

    def test_spoofed_policy_disallowed(self):
        x = disabled("codex")
        for key, value in (
            ("endpoint", "ai-squad-claude-bridge"),
            ("ok", True),
            ("auth_mode", "subscription"),
            ("policy", "anything"),
        ):
            bad = dict(x); bad[key] = value
            self.assertEqual(module.classify_health("codex", bad), "invalid")

    def test_canonical_callers_use_policy_gate(self):
        files = {
            "ops/ai-squad/install-codex-bridge-user-service.sh",
            "ops/ai-squad/install-claude-bridge-user-service.sh",
            "scripts/reconcile-ai-squad-runtime.sh",
            "scripts/deploy-production.sh",
        }
        for rel in files:
            with self.subTest(file=rel):
                s = (ROOT / rel).read_text()
                self.assertIn("bridge-health-policy.py", s)
        for role in ("codex", "claude"):
            source = (ROOT / f"ops/ai-squad/install-{role}-bridge-user-service.sh").read_text()
            self.assertIn('paid_bridge_manual_approved="${SHOPVIVALIZ_PAID_BRIDGE_MANUAL_APPROVED:-0}"', source)
            self.assertIn('paid_bridge_mode="${SHOPVIVALIZ_PAID_BRIDGE_MODE:-disabled}"', source)
            self.assertIn('0:disabled|1:interactive', source)
            self.assertIn(f"{role}-bridge.mjs", source)
            self.assertIn('staged_bridge=', source)


if __name__ == "__main__":
    unittest.main()
