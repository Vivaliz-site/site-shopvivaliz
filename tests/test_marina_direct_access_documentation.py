from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
HOST_ACCESS = ROOT / "docs" / "knowledge" / "host-access.md"

class MarinaDirectAccessDocumented(unittest.TestCase):
    def test_direct_route_does_not_depend_on_backend_vm(self):
        text = HOST_ACCESS.read_text(encoding="utf-8")
        assert "marina-a1-4c24g" in text
        assert "<!-- MARINA_DIRECT_RDC_V1 -->" in text
        section = text.split("<!-- MARINA_DIRECT_RDC_V1 -->", 1)[1].split("<!-- /MARINA_DIRECT_RDC_V1 -->", 1)[0]
        for required in ("Remote Desktop Commander", "sem passar pela controladora",
                         "NoNewPrivileges=yes", "ShopVivaliz Remote Control", "nao provisionado",
                         "RDC 2", "ESPOSA", "10.80.1.116"):
            with self.subTest(expected=required):
                self.assertIn(required, section)
        self.assertIn("nao contornar", section.lower())
        self.assertIn("credenciais separadas", section.lower())

if __name__ == "__main__":
    unittest.main()
