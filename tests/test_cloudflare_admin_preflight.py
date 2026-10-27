import importlib.util
from pathlib import Path
from types import SimpleNamespace
from unittest import mock
import stat
import tempfile
import unittest


p = Path(__file__).resolve().parents[1] / 'scripts' / 'cloudflare-admin-preflight.py'
spec = importlib.util.spec_from_file_location('cf_preflight', p)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

GOOD_BINARY = {
    'present': True,
    'approved_path': True,
    'regular_file': True,
    'root_owned': True,
    'approved_mode': True,
}


class PreflightTests(unittest.TestCase):
    def test_no_new_privileges(self):
        self.assertTrue(mod.no_new_privileges('Name:\tpython\nNoNewPrivs:\t1\n'))
        self.assertFalse(mod.no_new_privileges('NoNewPrivs:\t0\n'))
        self.assertIsNone(mod.no_new_privileges('Name: python\n'))

    def test_ss_listeners(self):
        self.assertTrue(mod.ssh_listener('LISTEN 0 4096 0.0.0.0:22 0.0.0.0:*\n'))
        self.assertFalse(mod.ssh_listener('LISTEN 0 4096 0.0.0.0:8080 0.0.0.0:*\n'))

    def test_root_is_not_cloudflare_auth(self):
        r = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g', 0, False, True, True,
                         GOOD_BINARY, 'active',
                         {'present': True, 'regular_file': True, 'mode_0600': True, 'root_owned': True, 'safe_parent_dirs': True})
        self.assertTrue(r['local_stage_prerequisites'])
        self.assertEqual(r['tunnel_authenticated'], 'NOT_TESTED')
        self.assertEqual(r['sudo_delegation'], 'NOT_TESTED')

    def test_unknown_no_new_privs_fails_closed(self):
        """A missing or unreadable /proc/self/status must never authorize staging."""
        safe_token = {'present': True, 'regular_file': True,
                      'mode_0600': True, 'root_owned': True, 'safe_parent_dirs': True}
        for nnp in (None, True):
            with self.subTest(nnp=nnp):
                result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                                      0, nnp, True, True, GOOD_BINARY,
                                      'inactive', safe_token)
                self.assertFalse(result['local_stage_prerequisites'])

    def test_stage_unit_unknown_privilege_status_rejected(self):
        """The root installer must demand an explicit NoNewPrivs=0."""
        script = (Path(__file__).resolve().parents[1] / 'scripts'
                  / 'cloudflare-admin-stage-unit.sh').read_text()
        self.assertIn('!= 0', script.split('NoNewPrivs:/ {print $2}', 1)[1][:40])

    def test_marina_no_new_privs_is_not_ready(self):
        r = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g', 1001, True, True, True,
                         {}, 'inactive',
                         {'present': False, 'regular_file': False, 'mode_0600': False, 'root_owned': False, 'safe_parent_dirs': False})
        self.assertFalse(r['local_stage_prerequisites'])
        self.assertEqual(r['status'], 'NOT_INSTALLED_OR_INACTIVE')

    def test_wrong_host_fails_closed(self):
        r = mod.evaluate('marina-a1-4c24g', 'shopvivaliz-free-a1', 0, False, True, True,
                         GOOD_BINARY, 'active',
                         {'present': True, 'regular_file': True, 'mode_0600': True, 'root_owned': True, 'safe_parent_dirs': True})
        self.assertFalse(r['local_stage_prerequisites'])
        self.assertEqual(r['status'], 'HOST_MISMATCH')

    def test_unapproved_binary_fails_closed(self):
        # Formerly any truthy shutil.which() path could satisfy stage-readiness.
        with tempfile.TemporaryDirectory() as tmp:
            candidate = Path(tmp) / 'cloudflared'
            candidate.write_bytes(b'not a trusted binary')
            candidate.chmod(0o755)
            metadata = mod.cloudflared_metadata(str(candidate))
        self.assertTrue(metadata['present'])
        self.assertFalse(metadata['approved_path'])
        safe_token = {'present': True, 'regular_file': True,
                      'mode_0600': True, 'root_owned': True, 'safe_parent_dirs': True}
        result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                              0, False, True, True, metadata, 'inactive', safe_token)
        self.assertFalse(result['local_stage_prerequisites'])
        self.assertEqual(result['tunnel_authenticated'], 'NOT_TESTED')

    def test_missing_binary_fails_closed(self):
        meta = mod.cloudflared_metadata(None)
        self.assertFalse(meta['present'])
        self.assertFalse(meta['approved_path'])

    def test_approved_root_owned_binary_metadata(self):
        approved = Path('/usr/bin/cloudflared')
        st = SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o755)
        with (mock.patch.object(mod.Path, 'resolve', return_value=approved),
              mock.patch.object(mod.Path, 'stat', return_value=st)):
            metadata = mod.cloudflared_metadata('/tmp/mock-path')
        self.assertEqual(metadata, GOOD_BINARY)

    def test_unsafe_binary_mode_rejected(self):
        approved = Path('/usr/bin/cloudflared')
        st = SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o777)
        with (mock.patch.object(mod.Path, 'resolve', return_value=approved),
              mock.patch.object(mod.Path, 'stat', return_value=st)):
            metadata = mod.cloudflared_metadata('/tmp/mock-path')
        self.assertFalse(metadata['approved_mode'])

    def test_untrusted_token_parent_mode_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp) / 'shopvivaliz'
            base.mkdir()
            token_dir = base / 'cloudflare-admin'
            token_dir.mkdir()
            token = token_dir / 'token'
            token.write_text('dummy-not-a-real-secret')
            token.chmod(0o600)
            token_dir.chmod(0o777)
            result = mod.token_metadata(token)
        self.assertFalse(result['safe_parent_dirs'])

    def test_missing_parent_attestation_cannot_be_ready(self):
        # A fabricated or legacy token metadata dict cannot omit a required gate.
        token = {'present': True, 'regular_file': True,
                 'mode_0600': True, 'root_owned': True}
        result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                              0, False, True, True, GOOD_BINARY, 'inactive', token)
        self.assertFalse(result['local_stage_prerequisites'])


if __name__ == '__main__':
    unittest.main()
