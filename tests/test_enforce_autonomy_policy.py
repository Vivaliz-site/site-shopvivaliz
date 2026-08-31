import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts' / 'enforce-autonomy-policy.py'
spec = importlib.util.spec_from_file_location('autonomy_policy', SCRIPT)
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class AutonomyPolicySemanticTests(unittest.TestCase):
    def test_workflow_inventory_name_is_not_stock_sensitive_by_path(self):
        self.assertFalse(policy.sensitive_path('.github/workflows/test-inventory.yml'))

    def test_real_inventory_source_path_remains_sensitive(self):
        self.assertTrue(policy.sensitive_path('includes/inventory.php'))

    def test_real_stock_mutation_in_workflow_content_remains_sensitive(self):
        mutation = 'curl -X ' + 'PO' + 'ST /api/catalog -d ' + 'sto' + 'ck=10'
        findings = policy.sensitive_content([mutation])
        self.assertTrue(findings)

    def test_test_source_is_scanned_for_real_mutation_content(self):
        self.assertTrue(policy.should_scan_content('tests/test_live_stock.py'))

    def test_stock_named_workflow_remains_sensitive_by_path(self):
        self.assertTrue(policy.sensitive_path('.github/workflows/stock-update.yml'))

    def test_workflow_content_is_still_scanned_for_real_mutations(self):
        self.assertTrue(policy.should_scan_content('.github/workflows/stock-update.yml'))

    def test_negative_test_assertion_is_not_a_commercial_mutation(self):
        assertion = '        self.assertNotIn("POST /api/catalog/stock", workflow_text)'
        self.assertEqual(policy.sensitive_content([assertion]), [])

    def test_assertion_fixture_assignment_is_not_a_commercial_mutation(self):
        fixture = '        assertion = \'self.assertNotIn("POST /api/catalog/stock", workflow_text)\''
        self.assertEqual(policy.sensitive_content([fixture]), [])

    def test_assertion_evaluated_argument_remains_sensitive(self):
        assertion = '        self.assertIn("ok", post("/api/catalog", {"stock": 10}))'
        self.assertTrue(policy.sensitive_content([assertion]))

    def test_assertion_fstring_expression_remains_sensitive(self):
        assertion = '        assertIn(f"{post(stock=10)}", response)'
        self.assertTrue(policy.sensitive_content([assertion]))

    def test_multiline_assertion_evaluated_mutation_remains_sensitive(self):
        lines = [
            '        self.assertIn(',
            '            "ok",',
            '            post(',
            '                "/api/catalog",',
            '                {"stock": 10},',
            '            ),',
            '        )',
        ]
        self.assertTrue(policy.sensitive_content(lines))


    def test_assertion_beyond_fifty_lines_remains_sensitive(self):
        lines = ['        self.assertIn(']
        lines += ['            # formatting spacer'] * 55
        lines += [
            '            "ok",',
            '            post(',
            '                "/api/catalog",',
            '                {"stock": 10},',
            '            ),',
            '        )',
        ]
        self.assertTrue(policy.sensitive_content(lines))


if __name__ == '__main__':
    unittest.main()
