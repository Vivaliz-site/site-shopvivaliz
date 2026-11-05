"""Contract tests for the Marina audit runner installer (no host is touched)."""
from __future__ import annotations

from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "install-marina-audit-runner.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "install-marina-audit-runner.yml"


class InstallerContractTests(unittest.TestCase):
    def setUp(self):
        self.text = SCRIPT.read_text(encoding="utf8")

    def test_script_is_bash_syntax_valid(self):
        result = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_never_runs_privileged_commands(self):
        # sudo may appear only inside the printed instruction, never as a command.
        for line in self.text.splitlines():
            stripped = line.strip()
            if stripped.startswith("echo") or stripped.startswith("#"):
                continue
            self.assertNotRegex(stripped, r"(^|[;&|]\s*)sudo\s", stripped)

    def test_token_is_read_from_env_and_never_echoed(self):
        self.assertIn('RUNNER_REG_TOKEN:-', self.text)
        # Only a variable expansion ($VAR / ${VAR}) inside echo would leak the value.
        self.assertIsNone(re.search(r"echo[^\n]*\$\{?RUNNER_REG_TOKEN\}?", self.text))

    def test_package_checksum_is_pinned_and_verified(self):
        self.assertRegex(self.text, r'RUNNER_SHA256="[0-9a-f]{64}"')
        self.assertIn("sha256sum -c", self.text)

    def test_uses_dedicated_audit_label_not_deploy_label(self):
        self.assertIn('RUNNER_LABEL="shopvivaliz-audit"', self.text)
        code = "\n".join(l for l in self.text.splitlines() if not l.lstrip().startswith("#"))
        self.assertNotIn("shopvivaliz-a1-deploy", code)

    def test_refuses_root_and_is_idempotent(self):
        self.assertIn('id -u', self.text)
        self.assertIn("RUNNER_ALREADY_CONFIGURED", self.text)


class WorkflowContractTests(unittest.TestCase):
    def setUp(self):
        self.text = WORKFLOW.read_text(encoding="utf8")

    def test_manual_dispatch_only(self):
        self.assertIn("workflow_dispatch:", self.text)
        for trigger in ("push:", "pull_request:", "schedule:"):
            self.assertNotIn(trigger, self.text)

    def test_requires_explicit_confirmation(self):
        self.assertIn("INSTALAR", self.text)

    def test_token_comes_from_secret_only(self):
        self.assertIn("secrets.MARINA_AUDIT_RUNNER_REG_TOKEN", self.text)


if __name__ == "__main__":
    unittest.main()
