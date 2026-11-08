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


class PriceSnapshotStabilityTests(unittest.TestCase):
    def sample_cache(self):
        return {
            "total": 1, "timestamp": "2026-11-02T10:00:00Z",
            "price_list_id": 991, "price_source": "olist_tiny_amazon",
            "itens": [item(10, "A", 100, 0, base_regular=90)],
        }

    def test_stock_only_refresh_keeps_price_snapshot_stable(self):
        from copy import deepcopy
        original = self.sample_cache()
        refreshed = deepcopy(original)
        refreshed["timestamp"] = "2026-11-02T10:05:00Z"
        refreshed["itens"][0]["_stock_synced_at"] = "2026-11-02T10:05:00Z"
        refreshed["itens"][0]["estoque_disponivel"] = 3
        refreshed["itens"][0]["estoque"] = {"quantidade": 3}
        refreshed["itens"][0]["anexos"] = [{"url": "https://example.com/test.jpg"}]
        self.assertEqual(audit.cache_price_fingerprint(original),
                         audit.cache_price_fingerprint(refreshed))

    def test_price_sku_base_price_and_provenance_changes_are_rejected(self):
        from copy import deepcopy
        original = self.sample_cache()
        sha = audit.cache_price_fingerprint(original)
        changes = (
            ("itens", "precos", "preco", 101),
            ("itens", "_erp_base_prices", "preco", 89),
            ("itens", "sku", None, "B"),
            ("itens", "_price_list_id", None, 992),
            ("itens", "_price_source", None, "unknown"),
            ("price_list_id", None, None, 992),
            ("price_source", None, None, "unknown"),
        )
        for outer, inner, leaf, value in changes:
            with self.subTest(field=(outer, inner, leaf)):
                newer = deepcopy(original)
                if outer == "itens":
                    target = newer["itens"][0]
                    if leaf is None:
                        target[inner] = value
                    else:
                        target[inner][leaf] = value
                else:
                    newer[outer] = value
                self.assertNotEqual(sha, audit.cache_price_fingerprint(newer))

    def test_public_snapshot_detects_price_and_sku_changes(self):
        from copy import deepcopy
        rows = [published(10, "A", 100), published(11, "B", 99)]
        original = audit.storefront_price_fingerprint(rows)
        self.assertEqual(original, audit.storefront_price_fingerprint(list(reversed(rows))))
        for key, value in (("price", 101), ("sku", "C"), ("olist_product_id", 999)):
            with self.subTest(key=key):
                newer = deepcopy(rows)
                newer[0][key] = value
                self.assertNotEqual(original, audit.storefront_price_fingerprint(newer))

    def test_invalid_cache_snapshot_fails_closed(self):
        with self.assertRaises(ValueError):
            audit.cache_price_fingerprint({"itens": [None]})
        with self.assertRaises(ValueError):
            audit.storefront_price_fingerprint([None])


class DetailRecoveryTests(unittest.TestCase):
    def test_retries_only_missing_ids_and_recovers(self):
        class API:
            calls = []
            def api_get(self, path, token, **kwargs):
                self.calls.append(path)
                if path.endswith("/2") and self.calls.count(path) == 1:
                    raise RuntimeError("temporary_throttle")
                return {"produto": {"id": path.split("/")[-1], "sku": "OK"}}
        api = API()
        details, errors = audit.fetch_details_with_targeted_retry(
            api, "synthetic", [active(1, "A"), active(2, "B")], sleep=lambda _: None)
        self.assertEqual(errors, [])
        self.assertEqual(set(details), {"1", "2"})
        self.assertEqual(api.calls, ["produtos/1", "produtos/2", "produtos/2"])

    def test_persistent_failure_is_not_hidden(self):
        class API:
            calls = 0
            def api_get(self, path, token, **kwargs):
                self.calls += 1
                raise RuntimeError("unavailable")
        api = API()
        details, errors = audit.fetch_details_with_targeted_retry(
            api, "synthetic", [active(3, "C")], sleep=lambda _: None)
        self.assertEqual(details, {})
        self.assertEqual(api.calls, 3)
        self.assertEqual(errors[0]["code"], "erp_detail_query_failed")


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

    def test_unpriced_structural_parent_is_excluded_but_child_price_is_audited(self):
        parent = item(100, "JVCD33-PAI", 0)
        parent.update({"tipo": "V", "tipoVariacao": "P", "estoque_disponivel": 0,
                       "variacoes": [{"id": 101, "sku": "JVCDPA33"}]})
        child = item(101, "JVCDPA33", 129.90)
        child["produtoPai"] = {"id": 100, "sku": "JVCD33-PAI"}
        result = evaluate(
            [active(100, "JVCD33-PAI"), active(101, "JVCDPA33")],
            {"100": parent, "101": child}, [parent, child],
            [published(101, "JVCDPA33", 129.90)])
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["counts"]["published_prices_matching_erp"], 1)
        self.assertEqual(result["counts"]["unpriced_structural_parents"], 1)
        self.assertEqual(result["issues"], [])
        self.assertIn({"id": "100", "sku": "JVCD33-PAI",
                       "reason": "unpriced_structural_parent"},
                      result["unpublished_exclusions"])

    def test_sku_suffix_alone_does_not_exempt_missing_sellable_price(self):
        product = item(100, "JVCD33-PAI", 0)
        product["tipo"] = "S"
        result = evaluate([active(100, "JVCD33-PAI")],
                          {"100": product}, [product], [])
        self.assertEqual(result["status"], "FAIL")
        self.assertIn("erp_price_invalid", {x["code"] for x in result["issues"]})

    def test_unpriced_parent_published_on_site_is_blocked(self):
        parent = item(100, "MODEL-PAI", 0)
        parent.update({"tipo": "V", "tipoVariacao": "P", "estoque_disponivel": 0,
                       "variacoes": [{"id": 101, "sku": "CHILD"}]})
        child = item(101, "CHILD", 50)
        result = evaluate([active(100, "MODEL-PAI"), active(101, "CHILD")],
                          {"100": parent, "101": child}, [parent, child],
                          [published(100, "MODEL-PAI", 10),
                           published(101, "CHILD", 50)])
        self.assertEqual(result["status"], "FAIL")
        self.assertIn("unpriced_parent_published", {x["code"] for x in result["issues"]})

    def test_unpriced_parent_with_available_stock_is_blocked(self):
        parent = item(100, "MODEL-PAI", 0)
        parent.update({"tipo": "V", "tipoVariacao": "P", "estoque_disponivel": 2,
                       "variacoes": [{"id": 101, "sku": "CHILD"}]})
        child = item(101, "CHILD", 50)
        result = evaluate([active(100, "MODEL-PAI"), active(101, "CHILD")],
                          {"100": parent, "101": child}, [parent, child],
                          [published(101, "CHILD", 50)])
        self.assertEqual(result["status"], "FAIL")
        self.assertIn("unpriced_parent_has_stock", {x["code"] for x in result["issues"]})

    def test_parent_link_in_child_detail_proves_active_variation_without_parent_list(self):
        parent = item(100, "GROUP", 0)
        parent.update({"tipo": "V", "tipoVariacao": "P", "estoque_disponivel": 0})
        child = item(101, "GROUP-RED", 50)
        child["produtoPai"] = {"id": 100, "sku": "GROUP"}
        data = evaluate([active(100, "GROUP"), active(101, "GROUP-RED")],
                        {"100": parent, "101": child}, [parent, child],
                        [published(101, "GROUP-RED", 50)])
        self.assertEqual(data["status"], "PASS")
        self.assertEqual(data["counts"]["unpriced_structural_parents"], 1)

    def test_invalid_child_price_still_blocks_parent_exemption(self):
        parent = item(100, "GROUP", 0)
        parent.update({"tipo": "V", "tipoVariacao": "P", "estoque_disponivel": 0,
                       "variacoes": [{"id": 101, "sku": "GROUP-RED"}]})
        child = item(101, "GROUP-RED", 0)
        child["produtoPai"] = {"id": 100, "sku": "GROUP"}
        data = evaluate([active(100, "GROUP"), active(101, "GROUP-RED")],
                        {"100": parent, "101": child}, [parent, child], [])
        self.assertEqual(data["status"], "FAIL")
        self.assertEqual(data["counts"]["unpriced_structural_parents"], 1)
        self.assertIn("erp_price_invalid", {x["code"] for x in data["issues"]})

    def test_parent_stock_fraction_or_unknown_must_not_round_to_zero(self):
        for stock in (0.001, "0.004", None, True, "invalid"):
            with self.subTest(stock=stock):
                parent = item(100, "MODEL-PAI", 0)
                parent.update({"tipo": "V", "estoque_disponivel": stock,
                               "variacoes": [{"id": 101, "sku": "CHILD"}]})
                child = item(101, "CHILD", 50)
                result = evaluate([active(100, "MODEL-PAI"), active(101, "CHILD")],
                                  {"100": parent, "101": child}, [parent, child],
                                  [published(101, "CHILD", 50)])
                self.assertEqual(result["status"], "FAIL")
                self.assertIn("unpriced_parent_has_stock",
                              {x["code"] for x in result["issues"]})

    def test_unpriced_parent_without_active_children_is_blocked(self):
        parent = item(100, "MODEL-PAI", 0)
        parent.update({"tipo": "V", "tipoVariacao": "P", "estoque_disponivel": 0,
                       "variacoes": [{"id": 101, "sku": "MISSING-CHILD"}]})
        result = evaluate([active(100, "MODEL-PAI")],
                          {"100": parent}, [parent], [])
        self.assertEqual(result["status"], "FAIL")
        self.assertIn("unpriced_parent_without_active_variations",
                      {x["code"] for x in result["issues"]})

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
