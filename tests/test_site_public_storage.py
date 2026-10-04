from __future__ import annotations

import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "site_public_storage.py"


def load_module():
    spec = importlib.util.spec_from_file_location("site_public_storage", MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class SitePublicStorageTests(unittest.TestCase):
    def test_publish_file_is_atomic_and_returns_public_url(self):
        module = load_module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            source = root / "source.jpg"
            source.write_bytes(b"image-bytes")
            with patch.dict(os.environ, {"SHOPVIVALIZ_PUBLIC_ROOT": str(root)}, clear=False):
                url = module.publish_file(source, "uploads/ai-images/SKU 1/hero.jpg")
            target = root / "uploads" / "ai-images" / "SKU 1" / "hero.jpg"
            self.assertEqual(target.read_bytes(), b"image-bytes")
            self.assertEqual(
                url,
                "https://shopvivaliz.com.br/uploads/ai-images/SKU%201/hero.jpg",
            )

    def test_path_traversal_is_rejected(self):
        module = load_module()
        with self.assertRaises(module.PublicStorageError):
            module.normalize_relative_path("../outside.txt")

    def test_override_root_is_honored(self):
        module = load_module()
        with tempfile.TemporaryDirectory() as td:
            with patch.dict(os.environ, {"SHOPVIVALIZ_PUBLIC_ROOT": td}, clear=False):
                self.assertEqual(module.project_root(), Path(td).resolve())


if __name__ == "__main__":
    unittest.main()
