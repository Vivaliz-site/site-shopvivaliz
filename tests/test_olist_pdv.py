"""Regression suite: all published prices MUST originate from the PDV list."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from scripts.olist_pdv import discover_pdv, fetch_pdv_snapshot, pdv_product_prices


class PdvPricesContractTest(unittest.TestCase):
    def setUp(self):
        self.pdv = {"id": 12345, "descricao": "PDV", "acrescimoDesconto": 15}
        self.product = {"preco": 100, "precoPromocional": 80}
        self.detail = {"id": 12345, "descricao": "PDV", "acrescimoDesconto": 15, "excecoes": None}

    def fetch(self, data):
        def get(path, token):
            self.assertEqual(path, "listas-precos/12345?idProduto=350339")
            self.assertEqual(token, "fixture-token")
            return data
        return get

    def test_percentage_base_and_promo(self):
        price = pdv_product_prices(self.fetch(self.detail), "fixture-token", 350339, self.product, self.pdv)
        self.assertEqual(price["preco"], 115.0)
        self.assertEqual(price["precoPromocional"], 92.0)
        self.assertEqual(price["price_source"], "olist_pdv")
        self.assertEqual(price["pdv_list_id"], 12345)

    def test_no_promo_null_in_erp(self):
        amount = pdv_product_prices(
            self.fetch(self.detail), "fixture-token", 350339,
            {"preco": 100, "precoPromocional": None}, self.pdv,
        )
        self.assertEqual(amount["precoPromocional"], 0.0)

    def test_negative_percentage(self):
        detail = dict(self.detail, acrescimoDesconto=-10)
        pdv = dict(self.pdv, acrescimoDesconto=-10)
        values = pdv_product_prices(self.fetch(detail), "fixture-token", 350339, self.product, pdv)
        self.assertEqual(values["preco"], 90.0)
        self.assertEqual(values["precoPromocional"], 72.0)

    def test_fixed_exception_and_promo(self):
        detail = dict(self.detail, excecoes={"idProduto": 350339, "codigo": "SKU", "preco": 102, "precoPromocional": 84})
        value = pdv_product_prices(self.fetch(detail), "fixture-token", 350339, self.product, self.pdv)
        self.assertEqual((value["preco"], value["precoPromocional"]), (102, 84))

    def test_equal_pdv_promo_is_not_a_discount(self):
        detail = dict(self.detail, excecoes={"idProduto": 350339, "preco": 1499, "precoPromocional": 1499})
        value = pdv_product_prices(self.fetch(detail), "fixture-token", 350339, self.product, self.pdv)
        self.assertEqual(value["preco"], 1499.0)
        self.assertEqual(value["precoPromocional"], 0.0)

    def test_pdv_promo_above_base_fails(self):
        detail = dict(self.detail, excecoes={"idProduto": 350339, "preco": 100, "precoPromocional": 101})
        with self.assertRaisesRegex(ValueError, "pdv_commercial_amount_invalid"):
            pdv_product_prices(self.fetch(detail), "fixture-token", 350339, self.product, self.pdv)

    def test_promo_removed(self):
        detail = dict(self.detail, excecoes={"idProduto": 350339, "preco": 102, "precoPromocional": None})
        value = pdv_product_prices(self.fetch(detail), "fixture-token", 350339, self.product, self.pdv)
        self.assertEqual(value["precoPromocional"], 0.0)

    def test_wrong_erp_product_id_in_exception(self):
        detail = dict(self.detail, excecoes={"idProduto": 387064728, "preco": 10})
        with self.assertRaisesRegex(ValueError, "pdv_exception_wrong_product"):
            pdv_product_prices(self.fetch(detail), "fixture-token", 350339, self.product, self.pdv)

    def test_wrong_list_name(self):
        with self.assertRaisesRegex(ValueError, "pdv_detail_identity_mismatch"):
            pdv_product_prices(self.fetch(dict(self.detail, descricao="OTHER")), "fixture-token", 350339, self.product, self.pdv)

    def test_percentage_changed_during_sync(self):
        with self.assertRaisesRegex(ValueError, "pdv_percent_changed_during_sync"):
            pdv_product_prices(self.fetch(dict(self.detail, acrescimoDesconto=13)), "fixture-token", 350339, self.product, self.pdv)

    def test_unverifiable_percent(self):
        no_percent = dict(self.detail, acrescimoDesconto=None)
        with self.assertRaisesRegex(ValueError, "pdv_percent_missing_without_fixed_price"):
            pdv_product_prices(self.fetch(no_percent), "fixture-token", 350339, self.product, dict(self.pdv, acrescimoDesconto=None))

    def test_commercial_invalid(self):
        with self.assertRaisesRegex(ValueError, "pdv_commercial_amount_invalid"):
            pdv_product_prices(self.fetch(self.detail), "fixture-token", 350339, {"preco": 100, "precoPromocional": 120}, self.pdv)

    def test_list_not_assumed_by_id(self):
        def listing(path, token):
            self.assertEqual(path, "listas-precos?limit=100&offset=0")
            return {"itens": [
                {"id": 982, "descricao": "Atacado", "acrescimoDesconto": 30},
                {"id": 17432, "descricao": "PDV", "acrescimoDesconto": 6},
            ], "paginacao": {"total": 2}}
        self.assertEqual(discover_pdv(listing, "fixture-token")["id"], 17432)

    def test_duplicate_pdv_name_is_ambiguous(self):
        def listing(path, token):
            return {"itens": [
                {"id": 1, "descricao": "PDV", "acrescimoDesconto": 4},
                {"id": 2, "descricao": "pdv", "acrescimoDesconto": 8},
            ], "paginacao": {"total": 2}}
        with self.assertRaisesRegex(ValueError, "pdv_list_not_unique"):
            discover_pdv(listing, "fixture-token")

    def test_bulk_pdv_exception_is_verified_without_individual_request(self):
        base = self.pdv
        bulk = {"id": 12345, "descricao": "PDV", "acrescimoDesconto": 15,
                "excecoes": [{"idProduto": 350339, "preco": 142, "precoPromocional": 119}]}
        calls = []
        def get(path, token):
            calls.append(path)
            self.assertEqual(path, "listas-precos/12345")
            return bulk
        snapshot = fetch_pdv_snapshot(get, "fixture-token", base)
        result = pdv_product_prices(get, "fixture-token", 350339, self.product, base, snapshot)
        self.assertEqual((result["preco"], result["precoPromocional"]), (142.0, 119.0))
        self.assertEqual(calls, ["listas-precos/12345"])

    def test_missing_bulk_exception_requires_live_individual_confirmation(self):
        bulk = {"id": 12345, "descricao": "PDV", "acrescimoDesconto": 15, "excecoes": []}
        target = dict(bulk, excecoes=None)
        calls = []
        def get(path, token):
            calls.append(path)
            return bulk if path == "listas-precos/12345" else target
        snapshot = fetch_pdv_snapshot(get, "fixture-token", self.pdv)
        result = pdv_product_prices(get, "fixture-token", 350339, self.product, self.pdv, snapshot)
        self.assertEqual(result["preco"], 115.0)
        self.assertEqual(calls, ["listas-precos/12345", "listas-precos/12345?idProduto=350339"])

    def test_ambiguous_bulk_exceptions_fail_closed(self):
        def get(path, token):
            return {"id": 12345, "descricao": "PDV", "acrescimoDesconto": 15,
                    "excecoes": [{"idProduto": 350339, "preco": 50},
                                 {"idProduto": 350339, "preco": 70}]}
        with self.assertRaisesRegex(ValueError, "pdv_exception_ambiguous"):
            fetch_pdv_snapshot(get, "fixture-token", self.pdv)

    def test_no_list_is_fail_closed(self):
        with self.assertRaisesRegex(ValueError, "pdv_list_not_unique"):
            discover_pdv(lambda path, token: {"itens": [], "paginacao": {"total": 0}}, "fixture-token")


class CatalogQuarantineContractTest(unittest.TestCase):
    """A collision is blocked for both IDs while other PDV rows stay eligible."""

    @staticmethod
    def module():
        import importlib.util
        spec = importlib.util.spec_from_file_location("pdv_catalog_daemon", Path(__file__).resolve().parents[1] / "daemon-sync-products.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_quarantine_all_colliding_ids_and_preserve_other_skus(self):
        sync = self.module()
        rows = [
            {"id": 342902508, "sku": "1C7Q-LKVS-MDOC"},
            {"id": 1, "sku": "SAFE-1"},
            {"id": 387064728, "sku": "1c7q-lkvs-mdoc"},
            {"id": 2, "sku": "SAFE-2"},
        ]
        good, quarantined = sync.partition_ambiguous_skus(rows)
        self.assertEqual([row["id"] for row in good], [1, 2])
        self.assertEqual(quarantined[0]["olist_product_ids"], ["342902508", "387064728"])
        self.assertEqual(quarantined[0]["reason"], "duplicate_active_sku")

    def test_missing_id_or_sku_never_publishes(self):
        sync = self.module()
        for row in ({"id": 1, "sku": ""}, {"id": None, "sku": "KNOWN"}):
            with self.assertRaisesRegex(ValueError, "erp_missing_or_duplicate_identity"):
                sync.partition_ambiguous_skus([row])

    def test_missing_summary_sku_is_recovered_only_from_verified_erp_id(self):
        sync = self.module()
        original = sync.api_get
        calls = []
        try:
            def provider(path, token):
                calls.append(path)
                return {"id": 42, "sku": "CANONICAL-42"}
            sync.api_get = provider
            rows, blocked = sync.recover_missing_skus([{"id": 42, "sku": ""}], "fixture-token")
            self.assertEqual([x["sku"] for x in rows], ["CANONICAL-42"])
            self.assertEqual(blocked, [])
            self.assertEqual(calls, ["produtos/42"])
        finally:
            sync.api_get = original

    def test_unrecoverable_sku_is_explicitly_quarantined(self):
        sync = self.module()
        original = sync.api_get
        try:
            sync.api_get = lambda path, token: {"id": 42, "sku": ""}
            rows, blocked = sync.recover_missing_skus([{"id": 42, "sku": ""}], "fixture-token")
            self.assertEqual(rows, [])
            self.assertEqual(blocked[0]["olist_product_ids"], ["42"])
            self.assertEqual(blocked[0]["reason"], "missing_erp_sku")
        finally:
            sync.api_get = original

    def test_missing_sku_id_mismatch_fails_closed(self):
        sync = self.module()
        original = sync.api_get
        try:
            sync.api_get = lambda path, token: {"id": 41, "sku": "WRONG"}
            with self.assertRaisesRegex(ValueError, "erp_product_id_mismatch"):
                sync.recover_missing_skus([{"id": 42, "sku": ""}], "fixture-token")
        finally:
            sync.api_get = original

    def test_quarantine_is_stored_in_same_atomic_snapshot(self):
        import tempfile
        sync = self.module()
        with tempfile.TemporaryDirectory() as tmp:
            original_cache, original_video = sync.CACHE_PATH, sync.VIDEO_SOURCES_PATH
            try:
                sync.CACHE_PATH = Path(tmp) / "storage" / "products-cache-ativos.json"
                sync.VIDEO_SOURCES_PATH = Path(tmp) / "storage" / "video-sources.json"
                quarantine = [{"sku": "DUP-1", "olist_product_ids": ["10", "11"], "reason": "duplicate_active_sku"}]
                sync.save_products([{"id": 12, "sku": "SAFE-1", "price_source": "olist_pdv", "pdv_list_id": 982}], quarantine, 3)
                import json
                snapshot = json.loads(sync.CACHE_PATH.read_text())
                self.assertEqual(snapshot["source_active_count"], 3)
                self.assertEqual(snapshot["total"], 1)
                self.assertEqual(snapshot["quarantined_product_count"], 2)
                self.assertEqual(snapshot["quarantined_skus"], quarantine)
            finally:
                sync.CACHE_PATH = original_cache
                sync.VIDEO_SOURCES_PATH = original_video


class PdvReleaseCutoverContractTest(unittest.TestCase):
    @staticmethod
    def module():
        import importlib.util
        spec = importlib.util.spec_from_file_location("pdv_release_cache", Path(__file__).resolve().parents[1] / "scripts/pdv_release_cache.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def payload(self):
        from datetime import datetime, timezone
        products = [
            {"id": idx, "sku": f"SKU-{idx:03d}", "price_source": "olist_pdv",
             "pdv_list_id": 982, "precos": {"preco": 45.0, "precoPromocional": 36.0}}
            for idx in range(1, 21)
        ]
        return {"itens": products, "total": len(products), "source_active_count": 22,
                "quarantined_skus": [{"sku": "DUP", "olist_product_ids": ["100", "101"], "reason": "duplicate_active_sku"}],
                "quarantined_product_count": 2, "timestamp": datetime.now(timezone.utc).isoformat()}

    def test_valid_quarantined_pdv_snapshot(self):
        check = self.module().verify_snapshot(self.payload())
        self.assertEqual(check["published_products"], 20)
        self.assertEqual(check["quarantined_products"], 2)
        self.assertEqual(check["pdv_list_id"], 982)

    def test_unverified_stale_and_duplicate_prices_are_rejected(self):
        mod = self.module()
        for change in ("source", "collision", "count", "promo"):
            payload = self.payload()
            if change == "source":
                payload["itens"][0]["price_source"] = "erp_product_registration"
            elif change == "collision":
                payload["itens"][1]["sku"] = payload["itens"][0]["sku"]
            elif change == "count":
                payload["source_active_count"] = 999
            else:
                payload["itens"][0]["precos"]["precoPromocional"] = 99
            with self.assertRaises(ValueError):
                mod.verify_snapshot(payload)

    def test_stage_validation_never_mutates_production_cache(self):
        import json
        import tempfile
        mod = self.module()
        sha = "a" * 40
        with tempfile.TemporaryDirectory() as tmp:
            old_stage, old_cache = mod.STAGE_ROOT, mod.CACHE
            try:
                mod.STAGE_ROOT = Path(tmp) / "isolated"
                mod.CACHE = Path(tmp) / "storage" / "products-cache-ativos.json"
                mod.STAGE_ROOT.mkdir()
                mod.CACHE.parent.mkdir()
                mod.CACHE.write_text("legacy-cache")
                staged = mod.stage_path_for(sha)
                staged.write_text(json.dumps(self.payload()))
                result = mod.run(sha, promote=False)
                self.assertEqual(result["status"], "validated_not_published")
                self.assertEqual(mod.CACHE.read_text(), "legacy-cache")
                promoted = mod.run(sha, promote=True)
                self.assertEqual(promoted["status"], "pdv_catalog_promoted_under_lock")
                self.assertEqual(json.loads(mod.CACHE.read_text())["total"], 20)
                self.assertEqual((mod.STAGE_ROOT / f".pdv-backup-{sha}.json").read_text(), "legacy-cache")
            finally:
                mod.STAGE_ROOT, mod.CACHE = old_stage, old_cache


if __name__ == "__main__":
    unittest.main()
