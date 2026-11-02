import importlib.util
import json
import multiprocessing
import os
import time
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



def _cross_process_throttle_worker(cache_path, barrier, queue):
    # fork children have their own module-level thread locks: this reproduces
    # the scheduled catalog sync and independent read-only audit processes.
    daemon.CACHE_PATH = Path(cache_path)
    daemon._MIN_REQUEST_INTERVAL = 0.18
    barrier.wait(timeout=10)
    daemon.throttle()
    queue.put(time.monotonic())




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

    @unittest.skipUnless(hasattr(os, "fork"), "production limiter uses Linux flock")
    def test_erp_api_budget_is_shared_between_independent_processes(self):
        ctx = multiprocessing.get_context("fork")
        barrier = ctx.Barrier(2)
        results = ctx.Queue()
        workers = [
            ctx.Process(target=_cross_process_throttle_worker,
                        args=(str(self.target), barrier, results))
            for _ in range(2)
        ]
        try:
            for proc in workers:
                proc.start()
            for proc in workers:
                proc.join(timeout=12)
                self.assertEqual(proc.exitcode, 0, "throttle worker failed")
            timestamps = sorted([results.get(timeout=3), results.get(timeout=3)])
            self.assertGreaterEqual(timestamps[1] - timestamps[0], 0.14)
            self.assertTrue((self.target.parent / "olist-erp-api-rate.lock").exists())
        finally:
            for proc in workers:
                if proc.is_alive():
                    proc.terminate()
                proc.join(timeout=1)
            results.close()

    def test_corrupt_rate_file_fails_closed(self):
        limiter = self.target.parent / "olist-erp-api-rate.lock"
        limiter.write_text("not-a-monotonic-timestamp")
        with self.assertRaisesRegex(RuntimeError, "erp_api_rate_file_invalid"):
            daemon.throttle()

    def test_rate_file_from_previous_boot_is_safely_replaced(self):
        limiter = self.target.parent / "olist-erp-api-rate.lock"
        future = time.monotonic() + 999
        limiter.write_text(str(future))
        with patch.object(daemon, "_MIN_REQUEST_INTERVAL", 0.01):
            daemon.throttle()
        self.assertLess(float(limiter.read_text().strip()), future)

    def test_partial_stock_reconcile_reports_failure_even_after_safe_cache_save(self):
        # A prior 35/202 partial stock refresh returned True, misleading
        # systemd into reporting a healthy run while stock was fail-closed.
        summaries = [{"id": "10"}, {"id": "11"}]
        with patch.object(daemon, "cache_revision", return_value=None), \\
             patch.object(daemon, "get_token", return_value="synthetic"), \\
             patch.object(daemon, "fetch_amazon_table", return_value=(0, {})), \\
             patch.object(daemon, "fetch_products_active", return_value=summaries), \\
             patch.object(daemon, "enrich_products", return_value=([row(45)], 1)), \\
             patch.object(daemon, "apply_amazon_prices", return_value=0), \\
             patch.object(daemon, "apply_kit_stock", return_value=0), \\
             patch.object(daemon, "save_products", return_value=self.target) as save:
            self.assertFalse(daemon.sync_once(1))
            save.assert_called_once()

    def test_complete_stock_reconcile_reports_success(self):
        summaries = [{"id": "10"}]
        with patch.object(daemon, "cache_revision", return_value=None), \\
             patch.object(daemon, "get_token", return_value="synthetic"), \\
             patch.object(daemon, "fetch_amazon_table", return_value=(0, {})), \\
             patch.object(daemon, "fetch_products_active", return_value=summaries), \\
             patch.object(daemon, "enrich_products", return_value=([row(45)], 0)), \\
             patch.object(daemon, "apply_amazon_prices", return_value=0), \\
             patch.object(daemon, "apply_kit_stock", return_value=0), \\
             patch.object(daemon, "save_products", return_value=self.target):
            self.assertTrue(daemon.sync_once(1))

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
