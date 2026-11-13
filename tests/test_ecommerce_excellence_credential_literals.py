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

    def test_remote_workflow_action_choices_are_scannable(self):
        # Gitleaks can interpret a long YAML/Python literal containing several
        # Cloudflare action identifiers as a token. Each existing identifier
        # must remain its own Python string literal on its own source line.
        workflow = (
            pathlib.Path(__file__).parents[1]
            / ".github/workflows/shopvivaliz-remote-access.yml"
        ).read_text(encoding="utf-8")
        lines = workflow.splitlines()
        starts = [i for i, line in enumerate(lines) if line.strip() == "actions = {"]
        self.assertEqual(1, len(starts))
        start = starts[0]
        end = next(i for i in range(start + 1, len(lines)) if lines[i].strip() == "}")
        entries = [line.strip() for line in lines[start + 1:end] if line.strip()]
        self.assertGreater(len(entries), 30)
        for entry in entries:
            self.assertTrue(entry.startswith('"') and entry.endswith('",'), entry)
            self.assertEqual(2, entry.count('"'), entry)

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
