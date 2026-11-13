#!/usr/bin/env python3
"""SEO discovery must follow storefront page= while keeping blog/legacy pagina=."""
import importlib.util
import sys
import unittest
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1] / "scripts" / "seo-integrity-audit.py"
spec = importlib.util.spec_from_file_location("shopvivaliz_seo_integrity", SOURCE)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

BASE = "https://shopvivaliz.com.br"

class CatalogPaginationDiscoveryTests(unittest.TestCase):
    def test_new_catalog_page_links_discovered(self):
        for n in (2, 3, 20):
            with self.subTest(page=n):
                self.assertTrue(module.is_discovery_page(f"{BASE}/catalogo/?page={n}", BASE))

    def test_legacy_catalog_and_blog_pagination(self):
        self.assertTrue(module.is_discovery_page(f"{BASE}/catalogo/?pagina=2", BASE))
        self.assertTrue(module.is_discovery_page(f"{BASE}/blog/?pagina=2", BASE))

    def test_non_pagination_links_not_discovered(self):
        for suffix in (
            "/catalogo/?page=1",
            "/catalogo/?page=0",
            "/catalogo/?page=NaN",
            "/catalogo/?page=2&page=3",
            "/catalogo/?page=2&pagina=3",
            "/catalogo/?q=vaso&page=2",
            "/blog/?page=2",
            "/produto/?page=2",
            "/catalogo/?page=2&ordem=price-asc",
        ):
            with self.subTest(url=suffix):
                self.assertFalse(module.is_discovery_page(BASE+suffix, BASE))

    def test_foreign_host_not_discovered(self):
        self.assertFalse(module.is_discovery_page("https://example.com/catalogo/?page=2", BASE))

if __name__ == "__main__":
    unittest.main()
