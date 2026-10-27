import importlib.util
from pathlib import Path
import unittest


p = Path(__file__).resolve().parents[1] / 'scripts' / 'cloudflare-admin-preflight.py'
spec = importlib.util.spec_from_file_location('cf_preflight', p)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


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
                         '/usr/bin/cloudflared', 'active',
                         {'present': True, 'regular_file': True, 'mode_0600': True, 'root_owned': True})
        self.assertTrue(r['local_stage_prerequisites'])
        self.assertEqual(r['tunnel_authenticated'], 'NOT_TESTED')
        self.assertEqual(r['sudo_delegation'], 'NOT_TESTED')

    def test_unknown_no_new_privs_fails_closed(self):
        """A missing or unreadable /proc/self/status must never authorize staging."""
        safe_token = {'present': True, 'regular_file': True,
                      'mode_0600': True, 'root_owned': True}
        for nnp in (None, True):
            with self.subTest(nnp=nnp):
                result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                                      0, nnp, True, True, '/usr/bin/cloudflared',
                                      'inactive', safe_token)
                self.assertFalse(result['local_stage_prerequisites'])

    def test_stage_unit_unknown_privilege_status_rejected(self):
        """The root installer must demand an explicit NoNewPrivs=0."""
        script = (Path(__file__).resolve().parents[1] / 'scripts'
                  / 'cloudflare-admin-stage-unit.sh').read_text()
        self.assertIn('!= 0', script.split('NoNewPrivs:/ {print $2}', 1)[1][:40])

    def test_marina_no_new_privs_is_not_ready(self):
        r = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g', 1001, True, True, True,
                         None, 'inactive',
                         {'present': False, 'regular_file': False, 'mode_0600': False, 'root_owned': False})
        self.assertFalse(r['local_stage_prerequisites'])
        self.assertEqual(r['status'], 'NOT_INSTALLED_OR_INACTIVE')

    def test_wrong_host_fails_closed(self):
        r = mod.evaluate('marina-a1-4c24g', 'shopvivaliz-free-a1', 0, False, True, True,
                         '/usr/bin/cloudflared', 'active',
                         {'present': True, 'regular_file': True, 'mode_0600': True, 'root_owned': True})
        self.assertFalse(r['local_stage_prerequisites'])
        self.assertEqual(r['status'], 'HOST_MISMATCH')


if __name__ == '__main__':
    unittest.main()
