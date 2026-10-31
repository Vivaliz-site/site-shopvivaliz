#!/usr/bin/env python3
"""Fail-closed regression for agents that stop Extreme Audit V5 after diagnosis."""
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "canonical_certifier_tests",
    ROOT / "tests" / "test_certify_audit_manifest.py",
)
assert SPEC and SPEC.loader
canonical = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(canonical)


class NoDiagnosticOnlyTests(unittest.TestCase):
    def test_diagnostic_report_with_open_finding_is_not_apto(self):
        data = canonical.valid_manifest()
        data["findings"]["p1"] = 1
        data["findings"]["open_defects_total"] = 1
        data["remediation"]["loop_completed"] = False
        data["remediation"]["open_blockers"] = 1
        data["remediation"]["executable_blockers"] = 1
        data["evidence"]["debt_count"] = 1
        verdict, failures = canonical.certifier.certify(data)
        self.assertEqual("NAO_APTO", verdict)
        self.assertTrue(failures)

    def test_documented_only_without_runtime_validation_is_not_apto(self):
        data = canonical.valid_manifest()
        data["runtime"]["no_silent_failure"] = False
        data["deploy"]["post_deploy_validation_complete"] = False
        verdict, failures = canonical.certifier.certify(data)
        self.assertEqual("NAO_APTO", verdict)
        self.assertTrue(failures)


if __name__ == "__main__":
    unittest.main()
