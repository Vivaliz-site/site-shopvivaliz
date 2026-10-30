from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
spec = importlib.util.spec_from_file_location("sv_stock_authority", SOURCE)
assert spec is not None and spec.loader is not None
daemon = importlib.util.module_from_spec(spec)
spec.loader.exec_module(daemon)


def catalog_row(product_id: str = "342902508", sku: str = "1C7Q-LKVS-MDOC", stock: int = 4) -> dict:
    return {
        "id": product_id,
        "sku": sku,
        "situacao": "A",
        "tipo": "P",
        "descricao": "Rodizio 75mm",
        "precos": {"preco": 38.9, "precoPromocional": 0},
        "_erp_base_prices": {"preco": 58.11, "precoPromocional": 0},
        "_price_source": "olist_tiny_amazon",
        "_price_list_id": 991,
        "estoque": {"quantidade": stock},
        "estoque_disponivel": stock,
        "_detail_synced_at": "2026-10-30T03:30:00+00:00",
        "_olist_updated_at": "2026-10-30",
    }


class ERPSellableStockTest(unittest.TestCase):
    def test_zero_authoritative_inventory_never_falls_back_to_physical_stock(self):
        row = catalog_row()
        row["estoque_disponivel"] = 0
        row["estoque"]["quantidade"] = 4
        product = daemon.public_product(row)
        self.assertEqual(0, product["estoque_disponivel"])
        self.assertEqual(0, product["estoque"]["quantidade"])

    def test_authoritative_field_precedence_and_validation(self):
        self.assertEqual(0, daemon.authoritative_stock_quantity({"disponivel": 0, "saldo": 4, "reservado": 4}))
        self.assertEqual(4, daemon.authoritative_stock_quantity({"disponivel": 4, "saldo": 4, "reservado": 0}))
        # The real Olist ERP returned -1 and -2 for two products: both are
        # valid inventory measurements with zero sellable units.
        self.assertEqual(0, daemon.authoritative_stock_quantity({"disponivel": -1, "saldo": -1, "reservado": 0}))
        self.assertEqual(0, daemon.authoritative_stock_quantity({"disponivel": -2, "saldo": -2, "reservado": 0}))
        for bad in ({"saldo": 4}, {"disponivel": None}, {"disponivel": True},
                    {"disponivel": "nan"}, {"disponivel": "infinity"}):
            with self.subTest(bad=bad), self.assertRaises(RuntimeError):
                daemon.authoritative_stock_quantity(bad)

    def test_reused_details_must_still_fetch_fresh_sellable_stock(self):
        previous = catalog_row()
        called = []
        def api(path, token, **kwargs):
            called.append(path)
            if path == "estoque/342902508":
                return {"disponivel": 0, "saldo": 4, "reservado": 4}
            raise AssertionError("fresh product details should be reused, not fetched")

        with patch.object(daemon, "load_previous_cache", return_value={"342902508": previous}), \
             patch.object(daemon, "detail_cache_is_fresh", return_value=True), \
             patch.object(daemon, "api_get", side_effect=api):
            products, failures = daemon.enrich_products([{"id": 342902508, "dataAlteracao": "2026-10-30"}],
                                                       "fake-token", workers=1)
        self.assertEqual(0, failures)
        self.assertEqual(["estoque/342902508"], called)
        self.assertEqual(0, products[0]["estoque_disponivel"])
        self.assertEqual({"quantidade": 0}, products[0]["estoque"])
        self.assertEqual(38.9, products[0]["precos"]["preco"])
        self.assertEqual(991, products[0]["_price_list_id"])

    def test_new_detail_must_not_republish_physical_reserved_inventory(self):
        def api(path, token, **kwargs):
            if path == "produtos/342902508":
                return catalog_row()
            if path == "estoque/342902508":
                return {"disponivel": 0, "saldo": 4, "reservado": 4}
            raise AssertionError(path)
        with patch.object(daemon, "load_previous_cache", return_value={}), \
             patch.object(daemon, "api_get", side_effect=api):
            products, failures = daemon.enrich_products([{"id": 342902508}], "fake-token", workers=1)
        self.assertEqual(0, failures)
        self.assertEqual(0, products[0]["estoque_disponivel"])
        self.assertEqual(0, products[0]["estoque"]["quantidade"])

    def test_missing_availability_fails_closed_for_old_product(self):
        old = catalog_row(stock=4)
        def api(path, token, **kwargs):
            return {"saldo": 4, "reservado": 4}
        with patch.object(daemon, "load_previous_cache", return_value={"342902508": old}), \
             patch.object(daemon, "detail_cache_is_fresh", return_value=True), \
             patch.object(daemon, "api_get", side_effect=api):
            products, failures = daemon.enrich_products([{"id": 342902508}], "fake-token", workers=1)
        self.assertEqual(1, failures)
        self.assertEqual(0, products[0]["estoque_disponivel"])
        self.assertEqual("unavailable", products[0]["_stock_sync_status"])
        self.assertEqual(38.9, products[0]["precos"]["preco"])

    def test_duplicate_sku_cannot_inflate_kit_availability(self):
        zero = catalog_row(stock=0)
        available = catalog_row("387064728", stock=4)
        kit = catalog_row("KIT-1", "KIT-1", stock=1)
        kit["tipo"] = "K"
        kit["kit"] = [{"sku": "1C7Q-LKVS-MDOC", "quantidade": 1}]
        count = daemon.apply_kit_stock([zero, available, kit])
        self.assertEqual(1, count)
        self.assertEqual(0, kit["estoque_disponivel"])


if __name__ == "__main__":
    unittest.main()
