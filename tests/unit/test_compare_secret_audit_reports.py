import json
import tempfile
import unittest
from pathlib import Path

from scripts.maintenance.compare_secret_audit_reports import compare_reports


def finding(*, severity="high", rule="secret_output_risk", path="x.sh", line=10, excerpt="TOKEN", active=True):
    return {
        "severity": severity,
        "rule": rule,
        "path": path,
        "line": line,
        "message": "unsafe secret handling",
        "excerpt": excerpt,
        "active": active,
    }


class CompareSecretAuditReportsTests(unittest.TestCase):
    def compare(self, base_findings, head_findings):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            base = root / "base.json"
            head = root / "head.json"
            base.write_text(json.dumps({"findings": base_findings}), encoding="utf-8")
            head.write_text(json.dumps({"findings": head_findings}), encoding="utf-8")
            return compare_reports(base, head)

    def test_identical_blocking_debt_does_not_block_pr(self):
        self.assertEqual([], self.compare([finding()], [finding()]))

    def test_line_number_shift_does_not_create_new_debt(self):
        self.assertEqual([], self.compare([finding(line=10)], [finding(line=99)]))

    def test_new_blocking_finding_blocks_pr(self):
        new = self.compare([], [finding(path="new.sh")])
        self.assertEqual(1, len(new))
        self.assertEqual("new.sh", new[0]["path"])

    def test_additional_duplicate_blocking_finding_is_detected(self):
        item = finding()
        new = self.compare([item], [item, dict(item, line=11)])
        self.assertEqual(1, len(new))

    def test_new_medium_legacy_debt_is_not_a_blocking_regression(self):
        self.assertEqual([], self.compare([], [finding(severity="medium", active=False)]))


if __name__ == "__main__":
    unittest.main()
