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

    def test_readonly_stock_validator_is_not_a_commercial_change_by_path(self):
        path = 'scripts/quality/validate-product-stock.php'
        self.assertFalse(policy.sensitive_path(path))
        self.assertTrue(policy.should_scan_content(path))

    def test_actual_stock_mutation_in_readonly_validator_still_requires_approval(self):
        verb = 'up' + 'date'
        field = 'sto' + 'ck'
        mutation = f'{verb} inventory set {field} = 5 where sku = "X"'
        self.assertTrue(policy.sensitive_content([mutation]))

    def test_readonly_validator_exact_addition_is_permitted(self):
        diff = ("diff --git a/validate.php b/validate.php\n"
                "@@ -11,0 +12 @@\n"
                "+if ($rows === []) $errors[] = 'empty_catalog_or_missing_cache';\n")
        self.assertTrue(policy.approved_readonly_stock_validator_change(diff))

    def test_readonly_validator_extra_change_is_not_permitted(self):
        diff = ("@@ -11,0 +12,2 @@\n"
                "+if ($rows === []) $errors[] = 'empty_catalog_or_missing_cache';\n"
                "+echo 'additional change';\n")
        self.assertFalse(policy.approved_readonly_stock_validator_change(diff))

    def test_readonly_validator_deleted_guard_is_not_permitted(self):
        diff = ("@@ -8 +8,2 @@\n"
                "-echo 'prior guard';\n"
                "+if ($rows === []) $errors[] = 'empty_catalog_or_missing_cache';\n")
        self.assertFalse(policy.approved_readonly_stock_validator_change(diff))

    def test_readonly_validator_change_other_than_guard_is_not_permitted(self):
        self.assertFalse(policy.approved_readonly_stock_validator_change(
            "@@ -11,0 +12 @@\n+echo 'another check';\n"
        ))

    def test_catalog_regression_fixture_is_scanned_not_owner_only_by_filename(self):
        path = policy.READONLY_CATALOG_TEST_PATH
        self.assertFalse(policy.sensitive_path(path))
        self.assertTrue(policy.should_scan_content(path))

    def test_generated_catalog_asset_matches_trusted_source_manifest(self):
        import json
        manifest = json.loads(
            (ROOT / 'public/dist/asset-manifest.json').read_text(encoding='utf-8')
        )
        path = manifest['assets']['/js/catalog.js']['file'].removeprefix('/')
        self.assertTrue(policy.verified_generated_catalog_asset(
            path, {'js/catalog.js', path}, ROOT
        ))

    def test_forged_generated_asset_fails_closed(self):
        import hashlib
        import json
        import tempfile
        source = "const quantity = 3;\n// harmless comment\nalert(quantity);\n"
        generated = policy.minify_catalog_source(source)
        digest = hashlib.sha256(generated).hexdigest()[:10]
        path = f'public/dist/js/catalog.{digest}.min.js'
        with tempfile.TemporaryDirectory(prefix='catalog-artifact-') as tmp:
            root = Path(tmp)
            (root / 'js').mkdir()
            (root / 'public/dist/js').mkdir(parents=True)
            (root / 'js/catalog.js').write_bytes(source.encode('utf-8'))
            (root / path).write_bytes(generated)
            entry = {
                'file': '/' + path,
                'bytes': len(generated),
                'source_bytes': len(source.encode('utf-8')),
            }
            manifest = {'assets': {'/js/catalog.js': entry}}
            manifest_path = root / 'public/dist/asset-manifest.json'
            manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
            changed = {'js/catalog.js', path}
            self.assertTrue(policy.verified_generated_catalog_asset(
                path, changed, root
            ))
            self.assertFalse(policy.verified_generated_catalog_asset(
                path, {path}, root
            ))
            (root / path).write_bytes(generated + b' extra')
            self.assertFalse(policy.verified_generated_catalog_asset(
                path, changed, root
            ))
            (root / path).write_bytes(generated)
            entry['source_bytes'] += 1
            manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
            self.assertFalse(policy.verified_generated_catalog_asset(
                path, changed, root
            ))
            manifest_path.unlink()
            self.assertFalse(policy.verified_generated_catalog_asset(
                path, changed, root
            ))

    def test_commercial_writes_still_fail_content_scan(self):
        operation = 'up' + 'date' + ' sto' + 'ck = 10'
        self.assertTrue(policy.sensitive_content([operation]))

    def test_real_stock_code_path_remains_sensitive(self):
        self.assertTrue(policy.sensitive_path('api/catalog/stock-by-product.php'))

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

    def test_non_assertion_text_does_not_hide_following_real_mutation(self):
        mutation = '        ' + 'po' + 'st("/api/catalog", {"sto' + 'ck": 10})'
        lines = [
            '        fixture = "self.assertIn("',
            mutation,
        ]
        self.assertTrue(policy.sensitive_content(lines))

    def test_incomplete_long_assertion_parses_ast_at_most_once(self):
        lines = ['        self.assertIn('] + ['            # formatting spacer'] * 5000
        original = policy.assertion_evaluated_text
        calls = 0

        def counting(source):
            nonlocal calls
            calls += 1
            return original(source)

        policy.assertion_evaluated_text = counting
        try:
            policy.sensitive_content(lines)
        finally:
            policy.assertion_evaluated_text = original
        self.assertLessEqual(calls, 1)


if __name__ == '__main__':
    unittest.main()
