from __future__ import annotations

import importlib.util
import json
import os
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "stock-alerts-email-cron.py"


def load_module():
    spec = importlib.util.spec_from_file_location("stock_alerts_email_cron", MODULE)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


class StockAlertBrevoMailTests(unittest.TestCase):
    def test_missing_brevo_key_fails_closed(self):
        with patch.dict(os.environ, {}, clear=True):
            m = load_module()
        self.assertFalse(m.send_email("buyer@example.com", "Produto", "SKU1", "token"))

    def test_payload_uses_fixed_shopvivaliz_identity(self):
        captured = {}

        class FakeResponse:
            status = 201
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self): return b'{"messageId":"test"}'

        def fake_urlopen(request, timeout=0):
            captured["payload"] = json.loads(request.data.decode("utf-8"))
            return FakeResponse()

        with patch.dict(os.environ, {
            "BREVO_API_KEY": "test-key",
            "EMAIL_FROM": "Wrong Legacy Identity <wrong@example.com>",
            "SMTP_HOST": "legacy.invalid",
        }, clear=True):
            m = load_module()
            with patch("urllib.request.urlopen", fake_urlopen):
                self.assertTrue(m.send_email("buyer@example.com", "Produto", "SKU1", "token"))

        payload = captured["payload"]
        self.assertEqual(payload["sender"], {
            "name": "ShopVivaliz",
            "email": "atendimento@shopvivaliz.com.br",
        })
        self.assertEqual(payload["replyTo"]["email"], "atendimento@shopvivaliz.com.br")
        self.assertIn("shopvivaliz-transactional", payload["tags"])


if __name__ == "__main__":
    unittest.main()
