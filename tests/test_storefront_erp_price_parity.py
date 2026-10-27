#!/usr/bin/env python3
"""Offline regression for the real (networked) post-deploy price parity gate."""
import importlib.util
import os
from pathlib import Path
import unittest

SCRIPT = Path(os.environ.get("SHOPVIVALIZ_PRICE_AUDIT_MODULE",
    Path(__file__).resolve().parents[1] / "scripts/quality/audit-storefront-erp-prices.py"))
SPEC = importlib.util.spec_from_file_location("shopvivaliz_price_parity", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


def active(pid, sku):
    return {"id": pid, "sku": sku, "situacao": "A"}


def item(pid, sku, regular, promo=0):
    return {"id": pid, "sku": sku, "situacao": "A",
            "precos": {"preco": regular, "precoPromocional": promo}}


def published(pid, sku, price):
    return {"olist_product_id": pid, "id": pid, "sku": sku, "price": price}


class PriceParityTests(unittest.TestCase):
    def test_all_real_prices_match(self):
        data = audit.evaluate(
            [active(11, "A"), active(12, "B")],
            {"11": item(11, "A", 10), "12": item(12, "B", 100, 80)},
            [item(11, "A", 10), item(12, "B", 100, 80)],
            [published(11, "A", 10), published(12, "B", 80)],
        )
        self.assertEqual(data["status"], "PASS")
        self.assertEqual(data["counts"]["erp_details_verified"], 2)
        self.assertEqual(data["counts"]["published_prices_matching_erp"], 2)

    def test_duplicate_erp_sku_with_different_prices_is_a_blocker(self):
        data = audit.evaluate(
            [active(342902508, "1C7Q-LKVS-MDOC"), active(387064728, "1C7Q-LKVS-MDOC")],
            {"342902508": item(342902508, "1C7Q-LKVS-MDOC", 58.11),
             "387064728": item(387064728, "1C7Q-LKVS-MDOC", 10)},
            [item(342902508, "1C7Q-LKVS-MDOC", 58.11),
             item(387064728, "1C7Q-LKVS-MDOC", 10)],
            [published(342902508, "1C7Q-LKVS-MDOC", 58.11)],
        )
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["counts"]["published_prices_matching_erp"], 1)
        self.assertEqual(data["counts"]["duplicate_erp_sku_price_conflicts"], 1)
        self.assertIn("duplicate_erp_sku", {row["code"] for row in data["issues"]})

    def test_caches_and_public_price_each_compared_to_erp(self):
        data = audit.evaluate([active(10, "A")],
            {"10": item(10, "A", 1499, 0)},
            [item(10, "A", 1747.99, 0)],
            [published(10, "A", 1747.99)])
        self.assertEqual(data["status"], "FAIL")
        codes={row["code"] for row in data["issues"]}
        self.assertIn("cache_price_differs_from_erp", codes)
        self.assertIn("storefront_price_differs_from_erp", codes)

    def test_promotion_removed_must_not_linger(self):
        data = audit.evaluate([active(10, "A")],
            {"10": item(10, "A", 100, 0)},
            [item(10, "A", 100, 90)],
            [published(10, "A", 90)])
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["counts"]["errors"], 2)

    def test_known_explicit_exclusion_and_missing_sku_are_reported(self):
        rows=[item(100, "Parafuso5x16", 0.01), item(101, "", 28.90)]
        data=audit.evaluate([active(100, "Parafuso5x16"), active(101, "")],
            {"100": rows[0], "101": rows[1]}, rows, [])
        self.assertEqual(data["status"], "PASS")
        self.assertEqual(data["counts"]["excluded_or_duplicate_not_published"], 2)
        self.assertEqual({x["reason"] for x in data["unpublished_exclusions"]},
            {"missing_sku", "explicit_storefront_exclusion"})

    def test_missing_unexcluded_product_is_a_blocker(self):
        data=audit.evaluate([active(100, "SKU")],
            {"100": item(100, "SKU", 100)}, [item(100, "SKU", 100)], [])
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["issues"][0]["code"], "active_priced_erp_product_not_published")

    def test_missing_erp_detail_is_a_blocker(self):
        data=audit.evaluate([active(100, "SKU")], {}, [item(100, "SKU", 100)],
                            [published(100, "SKU", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["issues"][0]["code"], "erp_detail_missing")

    def test_duplicate_published_erp_id_fails_even_if_last_price_matches(self):
        # Repeated catalog rows must not be hidden by dict normalization.
        data = audit.evaluate([active(100, "A")],
            {"100": item(100, "A", 100)}, [item(100, "A", 100)],
            [published(100, "A", 90), published(100, "A", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("duplicate_storefront_product_id",
                      {finding["code"] for finding in data["issues"]})

    def test_published_catalog_row_without_erp_id_fails(self):
        data = audit.evaluate([active(100, "A")],
            {"100": item(100, "A", 100)}, [item(100, "A", 100)],
            [published(100, "A", 100), {"sku": "ORPHAN", "price": 25}])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("published_product_missing_product_id",
                      {finding["code"] for finding in data["issues"]})

    def test_matching_price_does_not_hide_wrong_published_sku(self):
        data = audit.evaluate([active(100, "ERP-SKU")],
            {"100": item(100, "ERP-SKU", 100)},
            [item(100, "ERP-SKU", 100)],
            [published(100, "OTHER-SKU", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("storefront_sku_differs_from_erp",
                      {finding["code"] for finding in data["issues"]})

    def test_public_product_with_wrong_erp_id_is_a_blocker(self):
        data=audit.evaluate([active(100, "SKU")],
            {"100": item(100, "SKU", 100)}, [item(100, "SKU", 100)],
            [published(101, "SKU", 100)])
        self.assertEqual(data["status"], "FAIL")
        codes={x["code"] for x in data["issues"]}
        self.assertIn("published_product_missing_from_active_erp", codes)
        self.assertIn("active_priced_erp_product_not_published", codes)


if __name__ == "__main__":
    unittest.main()
