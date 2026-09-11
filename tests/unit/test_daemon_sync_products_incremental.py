import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[2] / "daemon-sync-products.py"
SPEC = importlib.util.spec_from_file_location("daemon_sync_products_incremental", MODULE_PATH)
daemon = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(daemon)


class IncrementalCatalogSyncTest(unittest.TestCase):
    def test_unchanged_product_reuses_cached_detail_without_api_calls(self):
        previous = {
            "10": {
                "id": 10,
                "sku": "SKU-10",
                "tipo": "K",
                "kit": [{"sku": "COMP-1", "quantidade": 2}],
                "anexos": [{"url": "https://cdn.example.test/10.jpg"}],
                "estoque_disponivel": 4,
                "_olist_updated_at": "2026-09-10T12:00:00Z",
            }
        }
        summaries = [{"id": 10, "situacao": "A", "dataAlteracao": "2026-09-10T12:00:00Z"}]
        with patch.object(daemon, "load_previous_cache", return_value=previous), patch.object(
            daemon, "api_get", side_effect=AssertionError("unchanged product must not hit API")
        ):
            products, failures = daemon.enrich_products(summaries, "token", workers=1)

        self.assertEqual(0, failures)
        self.assertEqual(previous["10"], products[0])
        self.assertEqual([{"sku": "COMP-1", "quantidade": 2}], products[0]["kit"])

    def test_changed_product_fetches_detail_and_stock_and_updates_marker(self):
        previous = {
            "10": {
                "id": 10,
                "sku": "SKU-10",
                "estoque_disponivel": 4,
                "_olist_updated_at": "2026-09-10T12:00:00Z",
            }
        }
        summaries = [{"id": 10, "situacao": "A", "dataAlteracao": "2026-09-11T12:00:00Z"}]
        calls = []

        def fake_api_get(path, token, **kwargs):
            calls.append(path)
            if path == "produtos/10":
                return {"id": 10, "sku": "SKU-10", "situacao": "A", "precos": {"preco": 20}, "estoque": {"quantidade": 8}}
            if path == "estoque/10":
                return {"disponivel": 6}
            raise AssertionError(path)
        with patch.object(daemon, "load_previous_cache", return_value=previous), patch.object(
            daemon, "api_get", side_effect=fake_api_get
        ):
            products, failures = daemon.enrich_products(summaries, "token", workers=1)

        self.assertEqual(0, failures)
        self.assertEqual(["produtos/10", "estoque/10"], calls)
        self.assertEqual(20.0, products[0]["precos"]["preco"])
        self.assertEqual(6, products[0]["estoque_disponivel"])
        self.assertEqual("2026-09-11T12:00:00Z", products[0]["_olist_updated_at"])
        self.assertEqual("tiny_v3", products[0]["sync_source"])

    def test_new_product_failure_is_not_published_and_will_retry(self):
        summaries = [{"id": 11, "situacao": "A", "dataAlteracao": "2026-09-11T12:00:00Z"}]
        with patch.object(daemon, "load_previous_cache", return_value={}), patch.object(
            daemon, "api_get", side_effect=RuntimeError("temporary outage")
        ):
            products, failures = daemon.enrich_products(summaries, "token", workers=1)

        self.assertEqual(1, failures)
        self.assertEqual([], products)

    def test_rate_limit_is_below_sixty_requests_per_minute(self):
        self.assertGreaterEqual(daemon._MIN_REQUEST_INTERVAL, 1.05)


if __name__ == "__main__":
    unittest.main()
