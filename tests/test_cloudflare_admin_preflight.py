import importlib.util
import os
import socket
import subprocess
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
    'lookup_path_approved': True,
    'safe_parent_dirs': True,
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
                         {'present': True, 'regular_file': True, 'mode_private': True, 'root_owned': True, 'safe_parent_dirs': True})
        self.assertTrue(r['local_stage_prerequisites'])
        self.assertEqual(r['tunnel_authenticated'], 'NOT_TESTED')
        self.assertEqual(r['sudo_delegation'], 'NOT_TESTED')

    def test_unknown_no_new_privs_fails_closed(self):
        """A missing or unreadable /proc/self/status must never authorize staging."""
        safe_token = {'present': True, 'regular_file': True,
                      'mode_private': True, 'root_owned': True, 'safe_parent_dirs': True}
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

    def test_python_preflight_refuses_hostile_path_commands(self):
        """RED: inherited PATH could execute arbitrary stubs in a root preflight."""
        with tempfile.TemporaryDirectory() as tmp:
            marker = Path(tmp) / 'subprocess-executed'
            for executable in ('systemctl', 'ss'):
                shadow = Path(tmp) / executable
                shadow.write_text('#!/bin/sh\n: > "' + str(marker) +
                                  '"\nprintf "active\n"\n')
                shadow.chmod(0o755)
            with mock.patch.dict(os.environ, {'PATH': tmp + os.pathsep + os.environ.get('PATH', '')}):
                mod.run_readonly('systemctl', 'is-active', 'ssh.service')
                mod.run_readonly('ss', '-H', '-ltn')
            self.assertFalse(marker.exists(), 'a caller-controlled command executed')
            self.assertEqual(mod.run_readonly('sh', '-c', 'echo unsafe'), '')
            self.assertEqual(mod.READONLY_EXECUTABLES['systemctl'], '/usr/bin/systemctl')
            self.assertEqual(mod.READONLY_EXECUTABLES['ss'], '/usr/bin/ss')

    def test_privileged_stager_ignores_inherited_path(self):
        """A PATH-supplied hostname must never run before the host gate."""
        if socket.gethostname().split('.')[0] == 'marina-a1-4c24g':
            self.skipTest('this negative test requires a non-Marina runner')
        stage_script = (Path(__file__).resolve().parents[1] / 'scripts'
                        / 'cloudflare-admin-stage-unit.sh')
        with tempfile.TemporaryDirectory() as tmp:
            fake_hostname = Path(tmp) / 'hostname'
            marker = Path(tmp) / 'host-command-executed'
            fake_hostname.write_text(
                '#!/bin/sh\n: > "' + str(marker) +
                '"\nprintf "marina-a1-4c24g\n"\n'
            )
            fake_hostname.chmod(0o755)
            env = dict(os.environ, PATH=tmp + os.pathsep + os.environ.get('PATH', ''))
            process = subprocess.run(
                ['/bin/bash', str(stage_script), 'marina-a1-4c24g'],
                env=env, text=True, capture_output=True, timeout=8, check=False
            )
            self.assertEqual(process.returncode, 2, process.stderr)
            self.assertIn('wrong host', process.stderr)
            self.assertFalse(marker.exists(), 'untrusted PATH command was executed')

    def test_stager_rejects_shadowing_and_duplicate_admin_units(self):
        """An already installed admin tunnel must not be silently replaced."""
        script = (Path(__file__).resolve().parents[1] / 'scripts'
                  / 'cloudflare-admin-stage-unit.sh').read_text()
        # Both candidate contracts are mutually exclusive until consolidated.
        for unit_name in ('shopvivaliz-cloudflare-admin.service',
                          'shopvivaliz-cloudflared-admin.service'):
            self.assertIn(unit_name, script)
        # Detect an inactive or disabled unit on disk, including stale drop-ins
        # and a future-boot enablement symlink, before creating the new unit.
        for guard in ('"$systemd_dir/$admin_name"',
                      '"$systemd_dir/$admin_name.d"',
                      '"$systemd_dir/multi-user.target.wants/$admin_name"',
                      '--property=FragmentPath', '--property=DropInPaths',
                      'systemctl is-enabled "$admin_name"',
                      'conflicting_cloudflare_admin_unit'):
            with self.subTest(guard=guard):
                self.assertIn(guard, script)
        self.assertLess(script.index('conflicting_cloudflare_admin_unit'),
                        script.index('unit_tmp="$(mktemp'))

    def test_canonical_contract_agrees_with_unit_template(self):
        unit = (Path(__file__).resolve().parents[1] / 'ops' / 'cloudflare-admin'
                / 'shopvivaliz-cloudflared-admin.service').read_text()
        stage = (Path(__file__).resolve().parents[1] / 'scripts'
                 / 'cloudflare-admin-stage-unit.sh').read_text()
        preflight = p.read_text()
        self.assertIn('ExecStart=/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared ', unit)
        self.assertIn('LoadCredential=tunnel-token:/etc/shopvivaliz/cloudflare-admin/token', unit)
        self.assertIn('ConditionPathExists=/etc/shopvivaliz/cloudflare-admin/access-approved', unit)
        self.assertIn('readonly CF_BIN=/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared', stage)
        self.assertIn('readonly UNIT=/etc/systemd/system/shopvivaliz-cloudflared-admin.service', stage)
        self.assertIn('readonly TOKEN_FILE="$TOKEN_DIR/token"', stage)
        self.assertIn('shopvivaliz-cloudflared-admin.service', preflight)
        self.assertIn("Path('/etc/shopvivaliz/cloudflare-admin/token')", preflight)
        # Stager embeds the exact reviewed template, rather than generating an
        # incompatible second contract at runtime.
        embedded = stage.split("cat > \"$unit_tmp\" <<'UNIT_TEMPLATE'\n", 1)[1].split(
            '\nUNIT_TEMPLATE', 1)[0]
        self.assertEqual(embedded.strip(), unit.strip())

    def test_marina_no_new_privs_is_not_ready(self):
        r = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g', 1001, True, True, True,
                         {}, 'inactive',
                         {'present': False, 'regular_file': False, 'mode_private': False, 'root_owned': False, 'safe_parent_dirs': False})
        self.assertFalse(r['local_stage_prerequisites'])
        self.assertEqual(r['status'], 'NOT_INSTALLED_OR_INACTIVE')

    def test_wrong_host_fails_closed(self):
        r = mod.evaluate('marina-a1-4c24g', 'shopvivaliz-free-a1', 0, False, True, True,
                         GOOD_BINARY, 'active',
                         {'present': True, 'regular_file': True, 'mode_private': True, 'root_owned': True, 'safe_parent_dirs': True})
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
                      'mode_private': True, 'root_owned': True, 'safe_parent_dirs': True}
        result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                              0, False, True, True, metadata, 'inactive', safe_token)
        self.assertFalse(result['local_stage_prerequisites'])
        self.assertEqual(result['tunnel_authenticated'], 'NOT_TESTED')

    def test_missing_binary_fails_closed(self):
        meta = mod.cloudflared_metadata(None)
        self.assertFalse(meta['present'])
        self.assertFalse(meta['approved_path'])

    def test_approved_root_owned_binary_metadata(self):
        approved = Path('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared')
        st = SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o755)
        with (mock.patch.object(mod.Path, 'resolve', return_value=approved),
              mock.patch.object(mod.Path, 'stat', return_value=st),
              mock.patch.object(mod, 'safe_binary_parent_dirs', return_value=True)):
            metadata = mod.cloudflared_metadata('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared')
        self.assertEqual(metadata, GOOD_BINARY)

    def test_unsafe_binary_mode_rejected(self):
        approved = Path('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared')
        st = SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o777)
        with (mock.patch.object(mod.Path, 'resolve', return_value=approved),
              mock.patch.object(mod.Path, 'stat', return_value=st),
              mock.patch.object(mod, 'safe_binary_parent_dirs', return_value=True)):
            metadata = mod.cloudflared_metadata('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared')
        self.assertFalse(metadata['approved_mode'])

    def test_approved_binary_cannot_be_spoofed_by_untrusted_lookup_path(self):
        """Symlink in a caller-supplied PATH to an approved inode is not readiness."""
        approved = Path('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared')
        st = SimpleNamespace(st_uid=0, st_mode=stat.S_IFREG | 0o755)
        with (mock.patch.object(mod.Path, 'resolve', return_value=approved),
              mock.patch.object(mod.Path, 'stat', return_value=st),
              mock.patch.object(mod, 'safe_binary_parent_dirs', return_value=True)):
            meta = mod.cloudflared_metadata('/tmp/hostile/cloudflared')
        self.assertTrue(meta['approved_path'])
        self.assertFalse(meta['lookup_path_approved'])
        token = {'present': True, 'regular_file': True,
                 'mode_private': True, 'root_owned': True, 'safe_parent_dirs': True}
        result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                              0, False, True, True, meta, 'inactive', token)
        self.assertFalse(result['local_stage_prerequisites'])

    def test_untrusted_binary_parent_mode_fails_closed(self):
        """A group-writable /usr/bin cannot pass even for a trusted binary leaf."""
        def fake_stat(path, **kwargs):
            mode = 0o777 if str(path) == '/opt/shopvivaliz-cloudflared-admin/releases' else 0o755
            return SimpleNamespace(st_uid=0, st_mode=stat.S_IFDIR | mode)
        with mock.patch.object(mod.Path, 'stat', autospec=True, side_effect=fake_stat):
            self.assertFalse(mod.safe_binary_parent_dirs(Path('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared')))

    def test_lookup_does_not_use_caller_path(self):
        """Binary lookup contract must match the fixed root staging locations."""
        self.assertEqual(mod.APPROVED_BINARY_PATHS,
                         ('/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared',))
        source = p.read_text()
        self.assertNotIn("shutil.which('cloudflared')", source)
        self.assertIn("cloudflared_metadata(approved_binary_candidate())", source)

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
                 'mode_private': True, 'root_owned': True}
        result = mod.evaluate('marina-a1-4c24g', 'marina-a1-4c24g',
                              0, False, True, True, GOOD_BINARY, 'inactive', token)
        self.assertFalse(result['local_stage_prerequisites'])


if __name__ == '__main__':
    unittest.main()
