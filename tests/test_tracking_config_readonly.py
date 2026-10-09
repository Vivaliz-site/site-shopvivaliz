"""Execute the real configuration CLI in an isolated, network-disabled fixture."""
from pathlib import Path
import os
import json
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class TrackingConfigReadonlyTests(unittest.TestCase):
    def run_validator(self, values=None, missing=None, dotenv=None, runtime=None):
        env = {'PATH': os.environ.get('PATH', ''), 'GA4_ID': 'G-1H55K1TZ5D',
               'GA4_SECRET': 'fixture-private-value-22',
               'GOOGLE_ADS_CONVERSION_LABEL': 'fixture-label-never-print'}
        env.update(values or {})
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for name in ('scripts', 'config', 'includes'):
                (root / name).mkdir()
            shutil.copyfile(ROOT / 'scripts/validate-tracking-config.php', root / 'scripts/validate-tracking-config.php')
            shutil.copyfile(ROOT / 'config/bootstrap-env.php', root / 'config/bootstrap-env.php')
            (root / 'includes/analytics-tracking.php').write_text(
                '<?php echo "TRACKING_LIBRARY_LOADED\\n"; class AnalyticsTracking { '
                'public static function sendPurchaseEventGA4(...$args) { '
                'echo "TRANSACTION_SENDER_CALLED\\n"; return true; }}')
            (root / 'includes/head-analytics.php').write_text('<?php')
            (root / 'pedido-confirmado.php').write_text('<?php')
            guard = root / 'network-guard.php'
            guard.write_text('<?php foreach (["http","https","ftp","ftps"] as $scheme) {'
                             'if (in_array($scheme, stream_get_wrappers(), true)) stream_wrapper_unregister($scheme); }')
            if dotenv is not None:
                (root / '.env').write_text(''.join(key + '=' + value + '\n' for key, value in dotenv.items()))
            if runtime is not None:
                payload = json.dumps(runtime).replace("'", "\\'")
                (root / 'config/runtime-secrets.php').write_text("<?php return json_decode('" + payload + "', true);")
            if missing:
                (root / missing).unlink()
            before = {str(p.relative_to(root)): p.read_bytes() for p in root.rglob('*') if p.is_file()}
            completed = subprocess.run(
                ['php', '-n', '-d', 'disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_init,curl_exec,socket_create', '-d', 'auto_prepend_file=' + str(guard), str(root / 'scripts/validate-tracking-config.php')],
                cwd=root, env=env, text=True, capture_output=True, timeout=10, check=False)
            after = {str(p.relative_to(root)): p.read_bytes() for p in root.rglob('*') if p.is_file()}
            self.assertEqual(before, after, 'configuration validation must not write files')
            return completed.returncode, completed.stdout + completed.stderr

    def test_configuration_probe_never_loads_sender_or_sends_purchase(self):
        code, output = self.run_validator()
        self.assertNotIn('TRANSACTION_SENDER_CALLED', output)
        self.assertNotIn('TRACKING_LIBRARY_LOADED', output)
        self.assertEqual(code, 0, output)
        self.assertIn('TRACKING_CONFIG=PASS', output)
        self.assertIn('TRACKING_VERIFICATION=STRUCTURAL_ONLY', output)
        self.assertIn('PURCHASE_DELIVERY=NOT_VERIFIED', output)

    def test_missing_or_placeholder_secret_fails_without_sending(self):
        for value in ('', '   ', 'YOUR_SECRET', 'replace-with-secret', 'changeme', 'placeholder'):
            with self.subTest(value=value):
                code, output = self.run_validator({'GA4_SECRET': value})
                self.assertEqual(code, 1, output)
                self.assertIn('TRACKING_CONFIG=FAIL', output)
                self.assertNotIn('TRANSACTION_SENDER_CALLED', output)

    def test_invalid_measurement_id_is_an_error_not_a_warning(self):
        for value in ('wrong-id', 'G-XXXXXXXXXX', 'G-1H55K1TZ5D/invalid'):
            with self.subTest(value=value):
                code, output = self.run_validator({'GA4_ID': value})
                self.assertEqual(code, 1, output)
                self.assertIn('TRACKING_CONFIG=FAIL', output)

    def test_whitespace_primary_does_not_override_runtime_precedence(self):
        code, output = self.run_validator({'GA4_ID': '   ', 'GOOGLE_ANALYTICS_ID': 'G-1H55K1TZ5D'})
        self.assertEqual(code, 1, output)

    def test_primary_placeholder_wins_over_valid_alias(self):
        code, output = self.run_validator({'GA4_ID': 'G-XXXXXXXXXX', 'GOOGLE_ANALYTICS_ID': 'G-1H55K1TZ5D'})
        self.assertEqual(code, 1, output)

    def test_legacy_alias_order_matches_tracking_runtime(self):
        code, output = self.run_validator({'GA4_ID': '', 'GOOGLE_ANALYTICS_ID': '',
                                          'GOOGLE_ANALYTICS': '', 'GOOGLE_ANALITYCS': 'G-1H55K1TZ5D'})
        self.assertEqual(code, 0, output)

    def test_dotenv_and_runtime_bootstrap_with_no_environment_values(self):
        fixture = {'GA4_ID': 'G-1H55K1TZ5D', 'GA4_SECRET': 'fixture-only-private'}
        for source in ('dotenv', 'runtime'):
            with self.subTest(source=source):
                code, output = self.run_validator({'GA4_ID': '', 'GA4_SECRET': ''}, **{source: fixture})
                self.assertEqual(code, 0, output)
                self.assertNotIn('fixture-only-private', output)
                self.assertNotIn('TRACKING_LIBRARY_LOADED', output)

    def test_existing_measurement_alias_is_supported(self):
        code, output = self.run_validator({'GA4_ID': '', 'GOOGLE_ANALYTICS_ID': 'G-1H55K1TZ5D'})
        self.assertEqual(code, 0, output)
        self.assertIn('TRACKING_CONFIG=PASS', output)

    def test_missing_required_files_fail(self):
        for path in ('includes/analytics-tracking.php', 'includes/head-analytics.php', 'pedido-confirmado.php'):
            with self.subTest(path=path):
                code, output = self.run_validator(missing=path)
                self.assertEqual(code, 1, output)
                self.assertIn('TRACKING_CONFIG=FAIL', output)

    def test_values_are_not_logged_or_subject_to_invented_secret_length(self):
        code, output = self.run_validator()
        self.assertEqual(code, 0, output)
        self.assertNotIn('fixture-private-value-22', output)
        self.assertNotIn('fixture-label-never-print', output)
        self.assertNotIn('Comprimento incomum', output)


if __name__ == '__main__':
    unittest.main()
