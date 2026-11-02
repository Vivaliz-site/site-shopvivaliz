"""Local auth bootstrap must be fail-closed, secret-redacted and idempotent."""
from __future__ import annotations

import importlib.util
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "operator_bootstrap_local_auth.py"
spec = importlib.util.spec_from_file_location("marina_bootstrap_auth", SOURCE)
assert spec is not None and spec.loader is not None
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class LocalAuthTest(unittest.TestCase):
    def setup_tree(self, root: Path):
        (root / ".config" / m.SERVICE_ACCOUNT).mkdir(parents=True)
        root.chmod(0o700)
        (root / ".config").chmod(0o700)
        (root / ".config" / m.SERVICE_ACCOUNT).chmod(0o700)

    def invoke(self, root):
        uid = os.geteuid()
        with patch.object(m.pwd, "getpwnam", return_value=type("User", (), {"pw_uid": uid})()):
            return m.bootstrap(home=root, host=m.HOST, uid=uid)

    def test_pair_created_without_output_of_secret_and_replay_refused(self):
        if os.geteuid() == 0:
            self.skipTest("must run test as non-root")
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / "private"
            root.mkdir()
            self.setup_tree(root)
            result = self.invoke(root)
            self.assertEqual(result, "LOCAL_AUTH_PAIR_CREATED_NO_TUNNEL_KEY")
            token = (root / ".config" / m.SERVICE_ACCOUNT / "auth-token").read_bytes()
            header = (root / ".config" / m.SERVICE_ACCOUNT / "auth-header").read_bytes()
            self.assertEqual(header, b"Bearer " + token)
            self.assertEqual(len(token), 64)
            self.assertNotIn(token.decode("ascii"), result)
            for name in m.FILES:
                self.assertEqual(stat.S_IMODE((root / ".config" / m.SERVICE_ACCOUNT / name).stat().st_mode), 0o600)
            self.assertEqual(self.invoke(root), "REFUSED_EXISTING_OR_PARTIAL_SECRET_PAIR")
            self.assertEqual((root / ".config" / m.SERVICE_ACCOUNT / "auth-token").read_bytes(), token)

    def test_refuse_unsafe_directory_and_partial_secret(self):
        if os.geteuid() == 0:
            self.skipTest("must run test as non-root")
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / "private"
            root.mkdir()
            self.setup_tree(root)
            (root / ".config").chmod(0o777)
            self.assertEqual(self.invoke(root), "REFUSED_PRIVATE_DIRECTORY_UNSAFE")
            (root / ".config").chmod(0o700)
            (root / ".config" / m.SERVICE_ACCOUNT / "auth-token").symlink_to("/nonexistent")
            self.assertEqual(self.invoke(root), "REFUSED_EXISTING_OR_PARTIAL_SECRET_PAIR")

    def test_wrong_host_and_identity_fail_before_files(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / "private"
            root.mkdir()
            self.setup_tree(root)
            self.assertEqual(m.bootstrap(home=root, host="retired-machine", uid=os.geteuid()), "REFUSED_WRONG_HOST")
            with patch.object(m.pwd, "getpwnam", return_value=type("User", (), {"pw_uid": 1001})()):
                self.assertEqual(m.bootstrap(home=root, host=m.HOST, uid=0), "REFUSED_WRONG_IDENTITY")


if __name__ == "__main__":
    unittest.main()
