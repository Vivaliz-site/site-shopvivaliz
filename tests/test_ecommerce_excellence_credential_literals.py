import importlib.util
import pathlib
import sys
import tempfile
import unittest


SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "ecommerce-excellence-audit.py"
SPEC = importlib.util.spec_from_file_location("ecommerce_excellence_audit", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class CredentialLiteralScannerTest(unittest.TestCase):
    def _scan(self, content: str):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            candidate = root / "candidate.py"
            candidate.write_text(content, encoding="utf-8")
            previous_root = MODULE.ROOT
            previous_tracked = MODULE.tracked_files
            try:
                MODULE.ROOT = root
                MODULE.tracked_files = lambda: [candidate]
                return MODULE.validate_static([candidate], scope="changed")
            finally:
                MODULE.ROOT = previous_root
                MODULE.tracked_files = previous_tracked

    def test_regexes_and_prefix_placeholders_are_not_credentials(self):
        classic_prefix = "ghp" + "_"
        project_prefix = "sk" + "-proj-"
        fine_grained_prefix = "github" + "_pat_"
        report = self._scan(
            f'PATTERN = r"{classic_prefix}[A-Za-z0-9]{{20,}}"\n'
            f'HELP = "{project_prefix}"\n'
            f'OTHER = r"{fine_grained_prefix}[A-Za-z0-9_]{{30,}}"\n'
        )
        credential_findings = [
            item for item in report["findings"] if item["code"] == "credential_literal"
        ]
        self.assertEqual([], credential_findings)

    def test_complete_synthetic_token_is_blocked(self):
        synthetic = "ghp" + "_" + ("A" * 36)
        report = self._scan(f'VALUE = "{synthetic}"\n')
        credential_findings = [
            item for item in report["findings"] if item["code"] == "credential_literal"
        ]
        self.assertEqual(1, len(credential_findings))
        self.assertEqual("blocker", credential_findings[0]["severity"])


if __name__ == "__main__":
    unittest.main()
