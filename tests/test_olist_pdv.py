"""Regression suite: all published prices MUST originate from the PDV list."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from scripts.olist_pdv import discover_pdv, pdv_product_prices


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

    def test_no_list_is_fail_closed(self):
        with self.assertRaisesRegex(ValueError, "pdv_list_not_unique"):
            discover_pdv(lambda path, token: {"itens": [], "paginacao": {"total": 0}}, "fixture-token")


if __name__ == "__main__":
    unittest.main()
