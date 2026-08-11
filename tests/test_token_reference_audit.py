from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "maintenance" / "token_reference_audit.py"
SPEC = importlib.util.spec_from_file_location("token_reference_audit", MODULE_PATH)
assert SPEC and SPEC.loader
AUDIT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = AUDIT
SPEC.loader.exec_module(AUDIT)


class TokenReferenceAuditTests(unittest.TestCase):
    def run_audit(self, files: dict[str, str], env_text: str = "") -> dict[str, object]:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name, content in files.items():
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
            env_path = root / ".env"
            env_path.write_text(env_text, encoding="utf-8")
            output = root / "report.json"
            status = AUDIT.main_for_paths(root, env_path, output)
            data = json.loads(output.read_text(encoding="utf-8"))
            data["status"] = status
            return data

    def test_github_action_secret_does_not_require_vm_runtime_env(self):
        data = self.run_audit({
            ".github/workflows/example.yml": """
name: Example
jobs:
  audit:
    steps:
      - run: echo ok
        env:
          AMAZON_LWA_CLIENT_ID: ${{ secrets.AMAZON_LWA_CLIENT_ID }}
""",
        })

        self.assertEqual(data["status"], 0)
        self.assertTrue(data["ok"])
        self.assertEqual(data["critical_count"], 0)

    def test_runtime_getenv_still_reports_missing_runtime_env(self):
        data = self.run_audit({
            "api/example.php": "<?php $token = getenv('SHOPVIVALIZ_UPDATE_TOKEN'); if (!$token) { throw new RuntimeException('missing'); }",
        })

        self.assertEqual(data["status"], 0)
        self.assertTrue(data["ok"])
        self.assertEqual(data["warnings"][0]["key"], "SHOPVIVALIZ_UPDATE_TOKEN")

    def test_runtime_placeholder_remains_blocking(self):
        data = self.run_audit(
            {
                "api/example.php": "<?php $token = getenv('SHOPVIVALIZ_UPDATE_TOKEN'); if (!$token) { throw new RuntimeException('missing'); }",
            },
            "SHOPVIVALIZ_UPDATE_TOKEN=changeme\n",
        )

        self.assertEqual(data["status"], 2)
        self.assertFalse(data["ok"])
        self.assertEqual(data["critical"][0]["reason"], "referenced_but_placeholder")


if __name__ == "__main__":
    unittest.main()
