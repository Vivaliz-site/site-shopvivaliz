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
        findings = policy.sensitive_content(['curl -X POST /api/catalog -d stock=10'])
        self.assertTrue(findings)

    def test_test_source_is_not_scanned_as_live_mutation_content(self):
        self.assertFalse(policy.should_scan_content('tests/test_enforce_autonomy_policy.py'))

    def test_workflow_content_is_still_scanned_for_real_mutations(self):
        self.assertTrue(policy.should_scan_content('.github/workflows/stock-update.yml'))


if __name__ == '__main__':
    unittest.main()
