import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
spec = importlib.util.spec_from_file_location("catalog_cache_concurrency", MODULE)
daemon = importlib.util.module_from_spec(spec)
assert spec is not None and spec.loader is not None
spec.loader.exec_module(daemon)


def row(price):
    return {
        "id": 341440872,
        "sku": "KIT4R-SOPRANO",
        "situacao": "A",
        "sync_source": "tiny_v3",
        "_erp_base_prices": {"preco": 45, "precoPromocional": 0},
        "_price_source": "olist_tiny_amazon",
        "_price_list_id": 991,
        "precos": {"preco": price, "precoPromocional": 0},
        "estoque_disponivel": 4,
    }


class CatalogConcurrencyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.target = Path(self.tmp.name) / "products-cache-ativos.json"
        patcher = patch.object(daemon, "CACHE_PATH", self.target)
        patcher.start()
        self.addCleanup(patcher.stop)
        patcher2 = patch.object(daemon, "load_video_sources", return_value={})
        patcher2.start()
        self.addCleanup(patcher2.stop)

    def current(self):
        return json.loads(self.target.read_text(encoding="utf-8"))["itens"][0]["precos"]["preco"]

    def test_stale_daemon_cannot_overwrite_newer_webhook_price(self):
        first = daemon.cache_revision(self.target)
        self.assertIsNone(first)
        daemon.save_products([row(45.00)], expected_revision=first)
        stale_revision = daemon.cache_revision(self.target)
        # Simulate a newer authenticated webhook's atomic update under the
        # same shared lock. The later daemon must fail closed.
        daemon.save_products([row(45.01)], expected_revision=stale_revision)
        self.assertEqual(45.01, self.current())
        with self.assertRaisesRegex(RuntimeError, "catalog_changed_during_reconcile_retry_later"):
            daemon.save_products([row(45.00)], expected_revision=stale_revision)
        self.assertEqual(45.01, self.current())

    def test_fresh_reconciliation_updates_atomically(self):
        daemon.save_products([row(45.00)], expected_revision=None)
        before = daemon.cache_revision(self.target)
        daemon.save_products([row(45.01)], expected_revision=before)
        self.assertEqual(45.01, self.current())
        self.assertIsNotNone(daemon.cache_revision(self.target))
        self.assertTrue((self.target.parent / "olist-erp-webhook.lock").exists())
        self.assertFalse(self.target.with_suffix(".json.tmp").exists())


if __name__ == "__main__":
    unittest.main()
