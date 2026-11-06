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
                         "NoNewPrivileges=yes", "MCP central e Orquestrador",
                         "Registro historico de 29/10", "RDC 2", "ESPOSA", "10.80.1.116"):
            with self.subTest(expected=required):
                self.assertIn(required, section)
        self.assertIn("nao contornar", section.lower())
        self.assertIn("credenciais separadas", section.lower())

    def test_current_marina_mcp_state_is_documented(self):
        readme = (ROOT / "marina-direct-mcp" / "README.md").read_text(encoding="utf-8")
        host = HOST_ACCESS.read_text(encoding="utf-8")
        for name, text in (("README", readme), ("host-access", host)):
            self.assertIn("<!-- MARINA_MCP_ACTIVE_20261104 -->", text, name)
            self.assertIn("<!-- /MARINA_MCP_ACTIVE_20261104 -->", text, name)
        block = readme.split("<!-- MARINA_MCP_ACTIVE_20261104 -->", 1)[1].split("<!-- /MARINA_MCP_ACTIVE_20261104 -->", 1)[0]
        for required in ("marina_mcp_agent_client.py", "MARINA_MCP_ACCESS_CLIENT_SECRET", "Quem le esses arquivos tem root",
                         "marina_admin_shell", "as_root", "marina_gui_session", "marina_browser_navigate",
                         "somente JWT", "root_http_daemon_allowed=false", "headless",
                         "ACTIVE_LOCAL_SMOKE_GREEN", "Esse documento nao declara APTO"):
            with self.subTest(expected=required):
                self.assertIn(required, block)
        # The documented grant file must be the one shipped in the repository.
        self.assertTrue((ROOT / "security" / "owner-approvals" / "3620.json").is_file())
        self.assertTrue((ROOT / "marina-direct-mcp" / "mcp-admin-grant.json").is_file())

if __name__ == "__main__":
    unittest.main()
