import importlib.util
import sys
import types
import unittest
from copy import deepcopy
from pathlib import Path
from unittest.mock import patch


class FakeClient:
    def __init__(self):
        self.update_calls = []
        self.item = {
            "item_id": 123,
            "item_name": "Produto original seguro",
            "description": "Descricao original suficientemente longa para validacao.",
            "attribute_list": [],
            "image": {"image_id_list": ["original-image"]},
            "price_info": [{"current_price": 10}],
            "stock_info_v2": {"summary_info": {"total_available_stock": 5}},
        }

    def update_product(self, item_id, *, title, description, image_ids):
        self.update_calls.append((item_id, title, description, list(image_ids)))
        self.item["item_name"] = title
        self.item["description"] = description
        self.item["image"] = {"image_id_list": list(image_ids)}
        return {"item_id": item_id}

    def get_product_details(self, _ids):
        return [deepcopy(self.item)]


def load_module():
    optimizer = types.ModuleType("full_catalog_optimizer")
    optimizer.build_title = lambda item: item["item_name"] + " Otimizado"
    optimizer.build_description = lambda item, title: title + "\nDescricao SEO completa e suficientemente longa."
    optimizer.image_ids = lambda item: list((item.get("image") or {}).get("image_id_list") or [])
    optimizer.smart_validate = lambda _before, _after: []
    client_module = types.ModuleType("utils.shopee_client")
    client_module.ShopeeClient = FakeClient
    modules = {
        "full_catalog_optimizer": optimizer,
        "utils": types.ModuleType("utils"),
        "utils.shopee_client": client_module,
    }
    with patch.dict(sys.modules, modules):
        path = (
            Path(__file__).resolve().parents[1]
            / "scripts"
            / "marketplace"
            / "shopee"
            / "production_seo_apply.py"
        )
        spec = importlib.util.spec_from_file_location("shopee_production_under_test", path)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(module)
        return module


class ProductionSeoTest(unittest.TestCase):
    def test_apply_one_requires_exact_readback_evidence(self):
        module = load_module()
        client = FakeClient()
        result = module.apply_one(client, deepcopy(client.item))
        self.assertEqual(result["status"], "updated_verified")
        self.assertTrue(result["evidence"]["read_after"])
        self.assertEqual(len(client.update_calls), 1)

    def test_verification_failure_rolls_back_and_raises(self):
        module = load_module()
        client = FakeClient()
        before = deepcopy(client.item)
        calls = 0
        original_get = client.get_product_details

        def mismatched_readback(ids):
            nonlocal calls
            calls += 1
            result = original_get(ids)
            if calls == 1:
                result[0]["item_name"] = "Valor diferente do solicitado"
            return result

        client.get_product_details = mismatched_readback
        with self.assertRaisesRegex(RuntimeError, "rollback=verified"):
            module.apply_one(client, before)
        self.assertEqual(len(client.update_calls), 2)
        self.assertEqual(client.item["item_name"], before["item_name"])
        self.assertEqual(client.item["description"], before["description"])

    def test_exact_verifier_detects_price_and_stock_changes(self):
        module = load_module()
        before = FakeClient().item
        expected = deepcopy(before)
        actual = deepcopy(expected)
        actual["price_info"] = [{"current_price": 999}]
        actual["stock_info_v2"] = {"summary_info": {"total_available_stock": 0}}
        errors = module.verify_exact(before, expected, actual)
        self.assertIn("price_invariant_violated", errors)
        self.assertIn("stock_invariant_violated", errors)


if __name__ == "__main__":
    unittest.main()
