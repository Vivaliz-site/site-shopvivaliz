#!/usr/bin/env python3
import importlib.util
import json
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "amazon_returns_runtime_gate", ROOT / "scripts/quality/amazon-returns-runtime-gate.py"
)
assert spec and spec.loader
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class AmazonReturnsRuntimeGateTests(unittest.TestCase):
    def test_accepts_aligned_live_component(self):
        sha = "a" * 40
        payload = {
            "ok": True,
            "components": {"amazon_returns": {
                "ok": True, "release_sha": sha, "service_state": "active"
            }},
        }
        with mock.patch.object(gate, "fetch_json", return_value=payload):
            with mock.patch.object(gate, "canonical_main_sha", return_value=sha):
                with mock.patch("sys.argv", ["gate"]):
                    self.assertEqual(gate.main(), 0)

    def test_rejects_release_mismatch(self):
        live = "a" * 40
        expected = "b" * 40
        payload = {
            "ok": True,
            "components": {"amazon_returns": {
                "ok": True, "release_sha": live, "service_state": "active"
            }},
        }
        with mock.patch.object(gate, "fetch_json", return_value=payload):
            with mock.patch.object(gate, "canonical_main_sha", return_value=expected):
                with mock.patch("sys.argv", ["gate"]):
                    self.assertEqual(gate.main(), 3)

    def test_rejects_inactive_service(self):
        sha = "a" * 40
        payload = {
            "ok": True,
            "components": {"amazon_returns": {
                "ok": False, "release_sha": sha, "service_state": "inactive"
            }},
        }
        with mock.patch.object(gate, "fetch_json", return_value=payload):
            with mock.patch.object(gate, "canonical_main_sha", return_value=sha):
                with mock.patch("sys.argv", ["gate"]):
                    self.assertEqual(gate.main(), 3)


if __name__ == "__main__":
    unittest.main()
