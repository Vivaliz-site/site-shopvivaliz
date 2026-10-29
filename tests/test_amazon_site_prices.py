import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
spec = importlib.util.spec_from_file_location("amazon_sync_daemon", MODULE_PATH)
daemon = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(daemon)


def table_fixture():
    return {
        "id": 991, "descricao": "Amazon", "acrescimoDesconto": 10,
        "excecoes": [
            {"idProduto": 20, "codigo": "EX-20", "preco": 37.89, "precoPromocional": 32.0}
        ],
    }


class AmazonSitePricesTest(unittest.TestCase):
    def test_general_exception_and_idempotent(self):
        with patch.object(daemon, "api_get", return_value=table_fixture()):
            table = daemon.fetch_amazon_table("fake-token")
        products = [
            {"id": 10, "sku": "N-10", "_erp_base_prices": {"preco": 15, "precoPromocional": 12}, "precos": {"preco": 999}},
            {"id": 20, "sku": "EX-20", "_erp_base_prices": {"preco": 15, "precoPromocional": 0}, "precos": {"preco": 999}},
        ]
        self.assertEqual(1, daemon.apply_amazon_prices(products, table))
        self.assertEqual({"preco": 16.5, "precoPromocional": 13.2}, products[0]["precos"])
        self.assertEqual({"preco": 37.89, "precoPromocional": 32.0}, products[1]["precos"])
        self.assertTrue(all(p["_price_list_id"] == 991 and p["sync_source"] == "tiny_v3" for p in products))
        self.assertEqual(1, daemon.apply_amazon_prices(products, table))
        self.assertEqual(16.5, products[0]["precos"]["preco"])

    def test_removed_exception_restores_erp_base(self):
        fixture = table_fixture()
        fixture["excecoes"] = []
        with patch.object(daemon, "api_get", return_value=fixture):
            table = daemon.fetch_amazon_table("fake-token")
        products = [{"id": 20, "_erp_base_prices": {"preco": 20.15, "precoPromocional": 0},
                    "precos": {"preco": 37.89}}]
        self.assertEqual(0, daemon.apply_amazon_prices(products, table))
        self.assertEqual(22.17, products[0]["precos"]["preco"])

    def test_invalid_table_fails_closed(self):
        for invalid in (
            {"id": 982, "descricao": "PDV", "acrescimoDesconto": 45, "excecoes": []},
            {"id": 991, "descricao": "Amazon", "acrescimoDesconto": 0},
            {"id": 991, "descricao": "Amazon", "acrescimoDesconto": 0, "excecoes": [{"idProduto": 2, "preco": 0}]},
        ):
            with patch.object(daemon, "api_get", return_value=invalid):
                with self.assertRaises(RuntimeError):
                    daemon.fetch_amazon_table("fake-token")
        with self.assertRaisesRegex(RuntimeError, "erp_base_price_unavailable"):
            daemon.apply_amazon_prices([{"id": 1, "precos": {"preco": 99}}], (0, {}))

    def test_api_failure_cannot_overwrite_cache(self):
        with patch.object(daemon, "get_token", return_value="fake-token"), \
                patch.object(daemon, "fetch_amazon_table", side_effect=RuntimeError("ERP unavailable")), \
                patch.object(daemon, "save_products", side_effect=AssertionError("must not write")):
            with self.assertRaisesRegex(RuntimeError, "ERP unavailable"):
                daemon.sync_once(workers=1)


if __name__ == "__main__":
    unittest.main()
