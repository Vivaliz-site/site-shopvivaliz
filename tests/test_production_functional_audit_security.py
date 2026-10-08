"""Exercise the real Bash audit and curl against disposable loopback HTTP."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / 'scripts/production-functional-audit.sh'
SENTINEL = 'synthetic-audit-key-0123456789'
BODY_SENTINEL = 'synthetic-private-provider-response'
REAL_CURL = shutil.which('curl')


class AuditFixture:
    def __init__(self, key=SENTINEL, error_path=None, integration_code=200,
                 bash_flags=(), curlrc=False, alias_only=False, overrides=None):
        self.key, self.error_path, self.integration_code = key, error_path, integration_code
        self.bash_flags, self.curlrc, self.alias_only = bash_flags, curlrc, alias_only
        self.overrides = overrides or {}
        self.requests = []
        self.tmp = tempfile.TemporaryDirectory(prefix='functional-audit-test-')
        self.root = Path(self.tmp.name)
        self.record = self.root / 'curl-metadata.jsonl'
        fixture = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def respond(self):
                path = urlsplit(self.path).path
                fixture.requests.append((path, self.headers.get('X-Agent-Key')))
                length = int(self.headers.get('Content-Length', '0'))
                if length:
                    self.rfile.read(length)
                code = 200
                if path == fixture.error_path:
                    code, body = 503, {'error': BODY_SENTINEL}
                elif path == '/api/catalog/products.php':
                    body = {'products': [{'id': '1', 'sku': 'FIXTURE', 'stock': 1}]}
                elif path == '/api/melhorenvio/shipping-check-v2.php':
                    body = {'ok': True, 'shipping_options': [{'id': 1}], 'shipping_total': 12}
                elif path == '/api/health/payment-queue.php':
                    body = {'ok': True, 'worker_ok': True, 'stale': 0, 'failed': 0}
                elif path == '/api/agent/integrations-health.php':
                    code = fixture.integration_code
                    body = {'integrations': [{'key': x, 'status': 'connected'}
                            for x in ('mercado_pago', 'melhor_envio', 'olist_tiny')],
                            'summary': {'failed': 0, 'attention': 1}}
                else:
                    body = {'ok': True}
                if path in fixture.overrides:
                    body = fixture.overrides[path]
                payload = json.dumps(body).encode()
                self.send_response(code)
                if 300 <= code < 400:
                    self.send_header('Location', '/redirected')
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            do_GET = respond
            do_POST = respond

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_args):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)
        self.tmp.cleanup()

    def run(self):
        wrapper = self.root / 'curl'
        wrapper.write_text('#!/usr/bin/env python3\n' + r'''
import json, os, sys
from pathlib import Path
key = os.environ['AUDIT_FIXTURE_KEY']
args = sys.argv[1:]
record = {
    'key_in_argv': bool(key) and any(key in arg for arg in args),
    'key_in_child_env': bool(key) and any(key in os.environ.get(name, '')
        for name in ('AGENT_KEY', 'SHOPVIVALIZ_AGENT_KEY')),
}
with Path(os.environ['AUDIT_FIXTURE_RECORD']).open('a') as out:
    out.write(json.dumps(record) + '\n')
os.execv(os.environ['AUDIT_FIXTURE_CURL'], [os.environ['AUDIT_FIXTURE_CURL'], *args])
''')
        wrapper.chmod(0o700)
        if self.curlrc:
            (self.root / '.curlrc').write_text('verbose\n')
        env = dict(os.environ)
        for name in ('BASH_ENV', 'ENV', 'BASH_XTRACEFD', 'SHELLOPTS', 'BASHOPTS'):
            env.pop(name, None)
        env.update({'PATH': str(self.root) + os.pathsep + env.get('PATH', ''),
                    'HOME': str(self.root), 'CURL_HOME': str(self.root),
                    'NO_PROXY': '127.0.0.1,localhost', 'no_proxy': '127.0.0.1,localhost',
                    'BASE_URL': f'http://127.0.0.1:{self.server.server_port}',
                    'SHOPVIVALIZ_AGENT_KEY': '' if self.alias_only else self.key,
                    'AGENT_KEY': self.key,
                    'AUDIT_FIXTURE_KEY': self.key,
                    'AUDIT_FIXTURE_RECORD': str(self.record),
                    'AUDIT_FIXTURE_CURL': REAL_CURL})
        result = subprocess.run(['bash', *self.bash_flags, str(AUDIT)], env=env, cwd=ROOT,
                                capture_output=True, text=True, timeout=30)
        self.observations = [json.loads(line) for line in self.record.read_text().splitlines()] if self.record.exists() else []
        return result


class FunctionalAuditSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not REAL_CURL or not shutil.which('bash'):
            raise RuntimeError('requires curl and bash')

    def assert_safe_success(self, fx):
        result = fx.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([key for path, key in fx.requests if path == '/api/agent/integrations-health.php'], [SENTINEL])
        self.assertTrue(all(key is None for path, key in fx.requests if path != '/api/agent/integrations-health.php'))
        self.assertIn('PRODUCTION_FUNCTIONAL_AUDIT=PASS', result.stdout)
        self.assertTrue(fx.observations)
        self.assertFalse(any(x['key_in_argv'] for x in fx.observations), 'key in process arguments')
        self.assertFalse(any(x['key_in_child_env'] for x in fx.observations), 'key exported to child')
        self.assertNotIn(SENTINEL, result.stdout + result.stderr)

    def test_auth_header_without_argv_or_child_environment_exposure(self):
        with AuditFixture() as fx:
            self.assert_safe_success(fx)

    def test_alias_key_remains_usable_without_exposure(self):
        with AuditFixture(alias_only=True) as fx:
            self.assert_safe_success(fx)

    def test_caller_xtrace_does_not_print_key(self):
        with AuditFixture(bash_flags=('-x',)) as fx:
            self.assert_safe_success(fx)

    def test_caller_allexport_does_not_reexport_key(self):
        with AuditFixture(bash_flags=('-a',)) as fx:
            self.assert_safe_success(fx)

    def test_curlrc_cannot_enable_credential_tracing(self):
        with AuditFixture(curlrc=True) as fx:
            self.assert_safe_success(fx)

    def test_error_bodies_are_not_written_to_audit_output(self):
        for path, stage in (('/api/orders/health.php', 'orders_health'),
                            ('/api/health/payment-queue.php', 'payment_queue'),
                            ('/api/melhorenvio/shipping-check-v2.php', 'melhor_envio'),
                            ('/api/agent/integrations-health.php', 'integrations')):
            with self.subTest(stage=stage), AuditFixture(error_path=path) as fx:
                result = fx.run()
                output = result.stdout + result.stderr
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(f'PRODUCTION_FUNCTIONAL_AUDIT=FAIL stage={stage}', output)
                self.assertIn('http=503', output)
                self.assertNotIn('PRODUCTION_FUNCTIONAL_AUDIT=PASS', output)
                self.assertNotIn(BODY_SENTINEL, output)

    def test_newline_in_credential_fails_before_any_request(self):
        for key in (SENTINEL + '\nX-Injected: yes', SENTINEL + '\rX-Injected: yes'):
            with self.subTest(kind='LF' if '\n' in key else 'CR'), AuditFixture(key=key) as fx:
                result = fx.run()
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(fx.requests, [])
                self.assertIn('stage=integrations detail=invalid agent key format', result.stderr)
                self.assertNotIn(SENTINEL, result.stdout + result.stderr)

    def test_missing_credential_still_fails_closed(self):
        with AuditFixture(key='') as fx:
            result = fx.run()
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('PRODUCTION_FUNCTIONAL_AUDIT=PASS', result.stdout)
            self.assertFalse(any(path == '/api/agent/integrations-health.php' for path, _ in fx.requests))
            self.assertIn('stage=integrations', result.stderr)
            self.assertIn('missing', result.stderr)

    def test_http_207_keeps_existing_success_semantics(self):
        with AuditFixture(integration_code=207) as fx:
            result = fx.run()
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('PRODUCTION_FUNCTIONAL_AUDIT=PASS', result.stdout)

    def test_malformed_numeric_payload_is_not_echoed(self):
        cases = (
            ('/api/health/payment-queue.php', {'ok': True, 'worker_ok': True,
              'stale': BODY_SENTINEL, 'failed': 0}),
            ('/api/catalog/products.php', {'products': [{'sku': 'FIXTURE', 'stock': BODY_SENTINEL}]}),
            ('/api/melhorenvio/shipping-check-v2.php', {'ok': True, 'shipping_options': [{}],
              'shipping_total': BODY_SENTINEL}),
        )
        for path, body in cases:
            with self.subTest(path=path), AuditFixture(overrides={path: body}) as fx:
                result = fx.run()
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn(BODY_SENTINEL, result.stdout + result.stderr)
                self.assertNotIn('PRODUCTION_FUNCTIONAL_AUDIT=PASS', result.stdout)

    def test_untrusted_critical_status_is_not_echoed(self):
        body = {'integrations': [{'key': 'mercado_pago', 'status': BODY_SENTINEL}]}
        with AuditFixture(overrides={'/api/agent/integrations-health.php': body}) as fx:
            result = fx.run()
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn(BODY_SENTINEL, result.stdout + result.stderr)

    def test_extra_summary_fields_are_not_echoed(self):
        body = {'integrations': [{'key': x, 'status': 'connected'}
                for x in ('mercado_pago', 'melhor_envio', 'olist_tiny')],
                'summary': {'failed': 0, 'extra': BODY_SENTINEL}}
        with AuditFixture(overrides={'/api/agent/integrations-health.php': body}) as fx:
            result = fx.run()
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertNotIn(BODY_SENTINEL, result.stdout + result.stderr)

    def test_optional_identifier_and_status_are_not_echoed(self):
        body = {'integrations': [{'key': x, 'status': 'connected'}
                for x in ('mercado_pago', 'melhor_envio', 'olist_tiny')] +
                [{'key': BODY_SENTINEL, 'status': BODY_SENTINEL}],
                'summary': {'failed': 0, 'attention': 1}}
        with AuditFixture(overrides={'/api/agent/integrations-health.php': body}) as fx:
            result = fx.run()
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertNotIn(BODY_SENTINEL, result.stdout + result.stderr)

    def test_missing_dependencies_fail_instead_of_skipping_the_gate(self):
        for keep in ('bash', 'curl'):
            with self.subTest(keep=keep), tempfile.TemporaryDirectory() as tmp:
                Path(tmp, keep).symlink_to(shutil.which(keep))
                env = dict(os.environ, PATH=tmp)
                result = subprocess.run([sys.executable, str(Path(__file__).resolve())],
                                        env=env, capture_output=True, text=True, timeout=20)
                self.assertNotEqual(result.returncode, 0, 'missing tool caused a false-green skipped suite')
                self.assertIn('requires curl and bash', result.stdout + result.stderr)

    def test_authentication_is_not_forwarded_on_redirect(self):
        with AuditFixture(integration_code=302) as fx:
            result = fx.run()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('stage=integrations detail=http=302', result.stderr)
            self.assertFalse(any(path == '/redirected' for path, _ in fx.requests))

    def test_failed_provider_summary_remains_a_failure(self):
        body = {'integrations': [{'key': x, 'status': 'connected'}
                for x in ('mercado_pago', 'melhor_envio', 'olist_tiny')],
                'summary': {'failed': 1}}
        with AuditFixture(overrides={'/api/agent/integrations-health.php': body}) as fx:
            result = fx.run()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('stage=integrations', result.stderr)
            self.assertNotIn('PRODUCTION_FUNCTIONAL_AUDIT=PASS', result.stdout)

    def test_regression_is_enforced_by_governance(self):
        source = (ROOT / 'scripts/repository-governance-validate.sh').read_text()
        self.assertIn('python3 tests/test_production_functional_audit_security.py', source)


if __name__ == '__main__':
    unittest.main()
