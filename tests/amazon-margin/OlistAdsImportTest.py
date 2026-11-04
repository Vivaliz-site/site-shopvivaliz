#!/usr/bin/env python3
"""Unit/regression tests for authenticated Olist Anuncios XLS/CSV importer."""
import importlib.util
import json
from pathlib import Path
from datetime import datetime, timezone
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/amazon-margin/import-olist-ads.py"
spec = importlib.util.spec_from_file_location("olist_ads_import", SCRIPT)
importer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(importer)

NOW = datetime(2026, 11, 4, 14, 0, tzinfo=timezone.utc)


class OlistAdsImporterTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    def make(self, stamp, rows, extension=".csv"):
        path = self.root / ("anuncios_" + stamp + extension)
        header = "Id;Integração;Identificador;Título;Produto (SKU);Preço de custo\n"
        text = header + "\n".join(
            f'{i};{channel};{seller};Teste;{product};1,23'
            for i, (channel, seller, product) in enumerate(rows, start=101)
        ) + "\n"
        path.write_text(text, encoding="utf-8")
        return path

    def test_all_three_integrations_and_repeats(self):
        a = self.make("2026-11-04-10-58-00",
                      [("Amazon", "03SFBA", "03S"),
                       ("Amazon Onsite", "ARM-A21", "A21")])
        b = self.make("2026-11-04-10-59-00",
                      [("Amazon Classic", "SLIM-1", "ASSENTO-SLIM"),
                       ("Amazon", "03SFBA", "03S")])
        data = importer.build_index([a, b], now=NOW)
        self.assertEqual(data["source"], "OLIST_ERP_ANUNCIOS_EXPORT")
        self.assertEqual(data["stats"]["unique_seller_skus"], 3)
        self.assertEqual(data["stats"]["fresh_unique"], 3)
        self.assertEqual(data["stats"]["conflicting"], 0)
        self.assertEqual(data["entries"]["03SFBA"]["integrations"], ["Amazon"])
        self.assertTrue(data["entries"]["ARM-A21"]["fresh"])
        self.assertEqual(data["entries"]["SLIM-1"]["erp_sku"], "ASSENTO-SLIM")
        self.assertNotIn("Preço de custo", json.dumps(data, ensure_ascii=False))

    def test_old_report_never_certifies(self):
        path = self.make("2026-07-27-12-51-25",
                         [("Amazon Onsite", "03SFBA", "03S")])
        data = importer.build_index([path], now=NOW)
        self.assertEqual(data["stats"]["historical_unique"], 1)
        self.assertFalse(data["entries"]["03SFBA"]["fresh"])
        self.assertEqual(data["entries"]["03SFBA"]["erp_sku"], "03S")

    def test_conflicting_product_mapping_is_refused(self):
        first = self.make("2026-11-04-10-51-00",
                          [("Amazon", "S-1", "SKU-A")])
        second = self.make("2026-11-04-10-52-00",
                           [("Amazon Onsite", "S-1", "SKU-B")])
        data = importer.build_index([first, second], now=NOW)
        self.assertEqual(data["stats"]["conflicting"], 1)
        self.assertEqual(data["entries"]["S-1"]["status"], "conflicting")
        self.assertIsNone(data["entries"]["S-1"]["erp_sku"])
        self.assertFalse(data["entries"]["S-1"]["fresh"])

    def test_no_amazon_rows_or_invalid_filename_is_refused(self):
        path = self.make("2026-11-04-10-58-30",
                         [("Mercado Livre", "ML-1", "ML-PROD")])
        with self.assertRaisesRegex(ValueError,"no_amazon_erp_ads"):
            importer.build_index([path], now=NOW)
        path = self.root / "renamed.csv"
        path.write_text("Id;Integração;Identificador;Produto (SKU)\n",encoding="utf-8")
        with self.assertRaisesRegex(ValueError,"missing_olist_export_timestamp"):
            importer.build_index([path], now=NOW)

    def test_future_export_is_refused(self):
        path = self.make("2026-11-07-10-58-00",
                         [("Amazon", "S-1", "SKU-1")])
        with self.assertRaisesRegex(ValueError,"future_olist_export"):
            importer.build_index([path], now=NOW)

    def test_atomic_save_has_provenance_and_private_mode(self):
        path = self.make("2026-11-04-10-58-00",
                         [("Amazon", "S-1", "SKU-1")])
        index = importer.build_index([path], now=NOW)
        out = self.root / "private" / "index.json"
        importer.atomic_save(index,out)
        result = json.loads(out.read_text(encoding="utf-8"))
        self.assertEqual(result["entries"]["S-1"]["erp_sku"], "SKU-1")
        self.assertEqual(out.stat().st_mode & 0o777, 0o640)
        self.assertEqual(len(result["files"][0]["sha256"]), 64)

    def test_xlsx_export(self):
        from openpyxl import Workbook
        wb=Workbook()
        sh=wb.active
        sh.append(["Id","Integração","Identificador","Título","Produto (SKU)"])
        sh.append([100,"Amazon Classic","S-1","Produto teste","P-1"])
        path=self.root/"anuncios_2026-11-04-10-57-00.xlsx"
        wb.save(path)
        index=importer.build_index([path],now=NOW)
        self.assertEqual(index["stats"]["fresh_unique"],1)
        self.assertEqual(index["entries"]["S-1"]["integrations"],["Amazon Classic"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
