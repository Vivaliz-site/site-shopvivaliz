from __future__ import annotations

import importlib.util
import json
import os
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "shopvivaliz_mail.py"

def load_module():
    spec = importlib.util.spec_from_file_location("shopvivaliz_mail", MODULE)
    if spec is None or spec.loader is None:
        raise RuntimeError("mail_helper_import_failed")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

class ShopVivalizMailProviderTests(unittest.TestCase):
    def test_missing_key_fails_closed(self):
        with patch.dict(os.environ, {}, clear=True):
            m = load_module()
            result = m.send_text("buyer@example.com", "Subject", "Body")
        self.assertFalse(result.success)
        self.assertEqual(result.error, "provider_not_configured")

    def test_fixed_identity_and_reply_to(self):
        captured = {}
        class FakeResponse:
            status = 201
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self): return b'{"messageId":"test-id"}'

        def fake_urlopen(request, timeout=0):
            captured["payload"] = json.loads(request.data.decode("utf-8"))
            return FakeResponse()

        with patch.dict(os.environ, {"BREVO_API_KEY":"test-key"}, clear=True):
            m = load_module()
            with patch("urllib.request.urlopen", fake_urlopen):
                result = m.send_html("buyer@example.com", "Subject", "<p>Hello</p>")

        self.assertTrue(result.success)
        self.assertEqual(captured["payload"]["sender"], {
            "name":"ShopVivaliz",
            "email":"atendimento@shopvivaliz.com.br",
        })
        self.assertEqual(
            captured["payload"]["replyTo"]["email"],
            "atendimento@shopvivaliz.com.br",
        )

    def test_forbidden_mei_brand_is_rejected(self):
        with patch.dict(os.environ, {"BREVO_API_KEY":"test-key"}, clear=True):
            m = load_module()
            for marker in (
                "Contabilidade Melo",
                "ContabilidadeMelo",
                "fiscalmelo@hotmail.com",
                "naoresponda@dev.shopvivaliz.com.br",
            ):
                result = m.send_text("buyer@example.com", "Subject", marker)
                self.assertFalse(result.success)
                self.assertEqual(result.error, "forbidden_brand_content")

if __name__ == "__main__":
    unittest.main()
