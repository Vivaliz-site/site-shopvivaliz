import importlib.util
import unittest
from pathlib import Path

module_path = Path(__file__).resolve().parents[1] / "scripts" / "production-smoke-test.py"
spec = importlib.util.spec_from_file_location("production_smoke_test", module_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class ProductUrlTests(unittest.TestCase):
    def test_percent_encodes_unicode_slug(self):
        url = module.build_product_url("vaso-decoração-34", "SKU-1")
        self.assertEqual(url, "https://shopvivaliz.com.br/produto/vaso-decora%C3%A7%C3%A3o-34")

    def test_falls_back_to_quoted_sku(self):
        url = module.build_product_url("", "TPJ/AS*BR1")
        self.assertEqual(url, "https://shopvivaliz.com.br/produto.php?sku=TPJ%2FAS%2ABR1")

if __name__ == "__main__":
    unittest.main()
