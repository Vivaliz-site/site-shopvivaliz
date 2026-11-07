#!/usr/bin/env python3
"""Regression for read-only Google Ads MCP interpreter selection."""
from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SRC = Path(__file__).resolve().parents[1] / "scripts" / "mcp-google-ads-readonly.py"
SPEC = importlib.util.spec_from_file_location("shopvivaliz_ads_mcp", SRC)
assert SPEC and SPEC.loader
MCP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MCP)


class GoogleAdsMcpPythonSelectionTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        root = Path(self.temporary.name) / "deploy"
        self.release = root / "releases" / "sha"
        self.release.mkdir(parents=True)
        self.shared_python = root / "shared" / "venv-google-ads" / "bin" / "python"
        self.shared_python.parent.mkdir(parents=True)
        self.original_root = MCP.ROOT
        MCP.ROOT = self.release
        self.addCleanup(lambda: setattr(MCP, "ROOT", self.original_root))

    def test_campaign_review_uses_existing_executable_shared_sdk(self) -> None:
        self.shared_python.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
        self.shared_python.chmod(0o755)
        with patch.object(MCP.subprocess, "run") as run:
            run.return_value.returncode = 0
            run.return_value.stdout = "READ_ONLY_REVIEW"
            run.return_value.stderr = ""
            response = MCP.run_script("google_ads_review_campaigns.py")
        self.assertEqual(run.call_args.args[0][0], str(self.shared_python))
        self.assertEqual(response["status"], "COMPROVADO")
        self.assertIn("READ_ONLY_REVIEW", response["stdout"])

    def test_campaign_review_falls_back_when_venv_missing(self) -> None:
        self.assertEqual(MCP.python_for_script("google_ads_review_campaigns.py"), sys.executable)

    def test_campaign_review_falls_back_when_venv_not_executable(self) -> None:
        self.shared_python.write_text("#! no execution", encoding="utf-8")
        self.shared_python.chmod(0o644)
        self.assertEqual(MCP.python_for_script("google_ads_review_campaigns.py"), sys.executable)

    def test_readiness_diagnostic_stays_on_ordinary_python(self) -> None:
        self.shared_python.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
        self.shared_python.chmod(0o755)
        self.assertEqual(MCP.python_for_script("google_ads_real_readiness.py"), sys.executable)


if __name__ == "__main__":
    unittest.main()
