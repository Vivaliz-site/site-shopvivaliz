import importlib.util
import sys
import tempfile
import types
import unittest
from copy import deepcopy
from pathlib import Path
from unittest.mock import patch


def repository_root() -> Path:
    for parent in Path(__file__).resolve().parents:
        if (parent / "scripts").is_dir() and (parent / ".github").is_dir():
            return parent
    raise RuntimeError("repository root not found")


class FakeClient:
    def __init__(self):
        self.upload_calls = []
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

    def iter_all_products(self):
        return [{"item_id": 123}]

    def get_product_details(self, _ids):
        return [deepcopy(self.item)]

    def upload_image(self, path):
        self.upload_calls.append(path)
        return "uploaded-image"

    def update_product(self, item_id, *, title, description, image_ids):
        self.update_calls.append({"item_id": item_id, "title": title, "description": description, "image_ids": list(image_ids)})
        self.item["item_name"] = title
        self.item["description"] = description
        self.item["image"] = {"image_id_list": list(image_ids)}


def load_optimizer():
    shopee_module = types.ModuleType("utils.shopee_client")
    shopee_module.ShopeeClient = FakeClient
    generator_module = types.ModuleType("ia.images.image_generator")
    generator_module.generate_for_product = lambda _item: {"main": Path("generated.png")}
    validator_module = types.ModuleType("ia.images.image_validator")
    validator_module.validate_batch = lambda generated: {key: (True, "ok") for key in generated}
    modules = {
        "utils": types.ModuleType("utils"),
        "utils.shopee_client": shopee_module,
        "ia": types.ModuleType("ia"),
        "ia.images": types.ModuleType("ia.images"),
        "ia.images.image_generator": generator_module,
        "ia.images.image_validator": validator_module,
    }
    with patch.dict(sys.modules, modules):
        path = repository_root() / "scripts" / "marketplace" / "shopee" / "full_catalog_optimizer.py"
        spec = importlib.util.spec_from_file_location("shopee_optimizer_under_test", path)
        module = importlib.util.module_from_spec(spec)
        assert spec and spec.loader
        spec.loader.exec_module(module)
        return module


class ShopeeOptimizerSafetyTest(unittest.TestCase):
    def test_dry_run_never_uploads_or_updates(self):
        optimizer = load_optimizer()
        client = FakeClient()
        with tempfile.TemporaryDirectory() as temp_dir:
            optimizer.BACKUP_DIR = Path(temp_dir) / "backups"
            optimizer.REPORT_DIR = Path(temp_dir) / "reports"
            with (
                patch.object(optimizer, "ShopeeClient", return_value=client),
                patch.object(optimizer.time, "sleep", return_value=None),
                patch.object(sys, "argv", ["optimizer", "--dry-run"]),
            ):
                self.assertEqual(optimizer.main(), 0)
        self.assertEqual(client.upload_calls, [])
        self.assertEqual(client.update_calls, [])

    def test_invariant_failure_rolls_back_original_listing(self):
        optimizer = load_optimizer()
        client = FakeClient()
        before = deepcopy(client.item)
        original_get = client.get_product_details
        verification_count = 0

        def get_with_price_violation(ids):
            nonlocal verification_count
            verification_count += 1
            result = original_get(ids)
            if verification_count == 1:
                result[0]["price_info"] = [{"current_price": 999}]
            return result

        client.get_product_details = get_with_price_violation
        with self.assertRaisesRegex(RuntimeError, "rollback completed"):
            optimizer.apply_product_update(
                client,
                before,
                title="Produto otimizado seguro",
                description="Descricao otimizada suficientemente longa para validacao.",
                images=["new-image"],
            )
        self.assertEqual(len(client.update_calls), 2)
        self.assertEqual(client.item["item_name"], before["item_name"])
        self.assertEqual(client.item["description"], before["description"])
        self.assertEqual(client.item["image"], before["image"])


if __name__ == "__main__":
    unittest.main()
