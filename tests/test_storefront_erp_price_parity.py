#!/usr/bin/env python3
"""Offline regression for the real (networked) post-deploy price parity gate."""
import importlib.util
import os
from decimal import Decimal
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


def item(pid, sku, regular, promo=0, *, base_regular=None, base_promo=None):
    base_regular = regular if base_regular is None else base_regular
    base_promo = promo if base_promo is None else base_promo
    return {"id": pid, "sku": sku, "situacao": "A",
            "precos": {"preco": regular, "precoPromocional": promo},
            "_erp_base_prices": {"preco": base_regular, "precoPromocional": base_promo},
            "_price_list_id": 991, "_price_source": "olist_tiny_amazon"}


def published(pid, sku, price):
    return {"olist_product_id": pid, "id": pid, "sku": sku, "price": price}


AMAZON_FLAT = (Decimal("0"), {})


def evaluate(active_rows, erp_details, cache_rows, site_rows, amazon_table=AMAZON_FLAT):
    return audit.evaluate(active_rows, erp_details, cache_rows, site_rows, amazon_table)


class PriceParityTests(unittest.TestCase):
    def test_all_real_prices_match(self):
        data = evaluate(
            [active(11, "A"), active(12, "B")],
            {"11": item(11, "A", 10), "12": item(12, "B", 100, 80)},
            [item(11, "A", 10), item(12, "B", 100, 80)],
            [published(11, "A", 10), published(12, "B", 80)],
        )
        self.assertEqual(data["status"], "PASS")
        self.assertEqual(data["counts"]["erp_details_verified"], 2)
        self.assertEqual(data["counts"]["published_prices_matching_erp"], 2)

    def test_duplicate_erp_sku_with_different_prices_is_a_blocker(self):
        data = evaluate(
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
        data = evaluate([active(10, "A")],
            {"10": item(10, "A", 1499, 0)},
            [item(10, "A", 1747.99, 0)],
            [published(10, "A", 1747.99)])
        self.assertEqual(data["status"], "FAIL")
        codes={row["code"] for row in data["issues"]}
        self.assertIn("cache_price_differs_from_erp", codes)
        self.assertIn("storefront_price_differs_from_erp", codes)

    def test_promotion_removed_must_not_linger(self):
        data = evaluate([active(10, "A")],
            {"10": item(10, "A", 100, 0)},
            [item(10, "A", 100, 90)],
            [published(10, "A", 90)])
        self.assertEqual(data["status"], "FAIL")
        codes = {issue["code"] for issue in data["issues"]}
        self.assertIn("cache_price_differs_from_erp", codes)
        self.assertIn("storefront_price_differs_from_erp", codes)

    def test_known_explicit_exclusion_and_missing_sku_are_reported(self):
        rows=[item(100, "Parafuso5x16", 0.01), item(101, "", 28.90)]
        data=evaluate([active(100, "Parafuso5x16"), active(101, "")],
            {"100": rows[0], "101": rows[1]}, rows, [])
        self.assertEqual(data["status"], "PASS")
        self.assertEqual(data["counts"]["excluded_or_duplicate_not_published"], 2)
        self.assertEqual({x["reason"] for x in data["unpublished_exclusions"]},
            {"missing_sku", "explicit_storefront_exclusion"})

    def test_missing_unexcluded_product_is_a_blocker(self):
        data=evaluate([active(100, "SKU")],
            {"100": item(100, "SKU", 100)}, [item(100, "SKU", 100)], [])
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["issues"][0]["code"], "active_priced_erp_product_not_published")

    def test_missing_erp_detail_is_a_blocker(self):
        data=evaluate([active(100, "SKU")], {}, [item(100, "SKU", 100)],
                            [published(100, "SKU", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["issues"][0]["code"], "erp_detail_missing")

    def test_duplicate_published_erp_id_fails_even_if_last_price_matches(self):
        # Repeated catalog rows must not be hidden by dict normalization.
        data = evaluate([active(100, "A")],
            {"100": item(100, "A", 100)}, [item(100, "A", 100)],
            [published(100, "A", 90), published(100, "A", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("duplicate_storefront_product_id",
                      {finding["code"] for finding in data["issues"]})

    def test_published_catalog_row_without_erp_id_fails(self):
        data = evaluate([active(100, "A")],
            {"100": item(100, "A", 100)}, [item(100, "A", 100)],
            [published(100, "A", 100), {"sku": "ORPHAN", "price": 25}])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("published_product_missing_product_id",
                      {finding["code"] for finding in data["issues"]})

    def test_matching_price_does_not_hide_wrong_published_sku(self):
        data = evaluate([active(100, "ERP-SKU")],
            {"100": item(100, "ERP-SKU", 100)},
            [item(100, "ERP-SKU", 100)],
            [published(100, "OTHER-SKU", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("storefront_sku_differs_from_erp",
                      {finding["code"] for finding in data["issues"]})

    def test_public_product_with_wrong_erp_id_is_a_blocker(self):
        data=evaluate([active(100, "SKU")],
            {"100": item(100, "SKU", 100)}, [item(100, "SKU", 100)],
            [published(101, "SKU", 100)])
        self.assertEqual(data["status"], "FAIL")
        codes={x["code"] for x in data["issues"]}
        self.assertIn("published_product_missing_from_active_erp", codes)
        self.assertIn("active_priced_erp_product_not_published", codes)

    def test_amazon_percent_rule_must_override_raw_registration_price(self):
        table = (Decimal("20"), {})
        data = evaluate([active(10, "A")], {"10": item(10, "A", 100)},
            [item(10, "A", 120, base_regular=100)],
            [published(10, "A", 120)], table)
        self.assertEqual(data["status"], "PASS")
        stale = evaluate([active(10, "A")], {"10": item(10, "A", 100)},
            [item(10, "A", 100)], [published(10, "A", 100)], table)
        self.assertEqual(stale["status"], "FAIL")
        self.assertIn("storefront_price_differs_from_erp",
                      {x["code"] for x in stale["issues"]})

    def test_amazon_exact_id_exception_overrides_percent(self):
        table = (Decimal("20"), {"353693135": (Decimal("1299"), Decimal("0"))})
        data = evaluate([active(353693135, "C06TVM")],
            {"353693135": item(353693135, "C06TVM", 1499)},
            [item(353693135, "C06TVM", 1299, base_regular=1499)],
            [published(353693135, "C06TVM", 1299)], table)
        self.assertEqual(data["status"], "PASS")

    def test_amazon_promotional_price_rounding(self):
        table = (Decimal("10"), {})
        data = evaluate([active(10, "A")],
            {"10": item(10, "A", 99.99, 80.00)},
            [item(10, "A", 109.99, 88.00, base_regular=99.99, base_promo=80)],
            [published(10, "A", 88.00)], table)
        self.assertEqual(data["status"], "PASS")

    def test_missing_amazon_provenance_is_rejected_even_for_same_number(self):
        cached = item(10, "A", 100)
        cached["_price_list_id"] = 982
        data = evaluate([active(10, "A")], {"10": item(10, "A", 100)},
                        [cached], [published(10, "A", 100)])
        self.assertEqual(data["status"], "FAIL")
        self.assertIn("amazon_price_provenance_missing",
                      {x["code"] for x in data["issues"]})


if __name__ == "__main__":
    unittest.main()
