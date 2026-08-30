from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/google-ads-sync-production-runtime.yml"


class GoogleAdsSyncProductionRuntimeTests(unittest.TestCase):
    def test_production_a1_host_has_safe_fallback_when_secret_is_missing(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        expected = "VM_HOST: ${{ secrets.SHOPVIVALIZ_VM_HOST || " + chr(39) + "163.176.103.253" + chr(39) + " }}"
        self.assertIn(expected, text)


if __name__ == "__main__":
    unittest.main()
