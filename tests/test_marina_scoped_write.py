"""Scoped file-writing behavior must fail closed on grants, paths and CAS."""
import importlib.util
import hashlib
import os
import pwd
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "scoped_write.py"
spec = importlib.util.spec_from_file_location("marina_scoped_write", MODULE)
assert spec is not None and spec.loader is not None
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class Operator:
    """Test-only fake. Not a real security grant or trusted host executor."""
    def __init__(self):
        self.ok = True
        self.reservations = set()
        self.last_fence = 0
        self.audit = []

    def grant_valid(self, pr, head, merge, capability, operation, repository):
        return self.ok and pr == 424242 and capability == "file_write" and operation == m.OPERATION and repository == m.REPOSITORY

    def runtime_permission_valid(self):
        return self.ok

    def reserve_lease_once(self, operation, key, fence):
        if key in self.reservations or fence <= self.last_fence:
            return False
        self.reservations.add(key)
        self.last_fence = fence
        return True

    def audit_prepared(self, operation, key, name, digest):
        self.audit.append(("prepared", key, name, digest))
        return True

    def audit_committed(self, operation, key, name, digest):
        self.audit.append(("committed", key, name, digest))
        return True


class ScopedWriteContract(unittest.TestCase):
    def setUp(self):
        if os.geteuid() == 0:
            self.skipTest("workspace writer must be tested as a non-root identity")
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name) / "workspace"
        self.base.mkdir(mode=0o700)
        self.base.chmod(0o700)
        self.a = Operator()
        self.request = {"filename": "status.json", "content_utf8": '{"ok":true}',
                        "expected_sha256": None, "pr_number": 424242,
                        "head_sha": "a" * 40, "merge_sha": "b" * 40,
                        "idempotency_key": "firstwrite-00001", "fencing_token": 1}
        self.host = patch.object(m.socket, "gethostname", return_value=m.HOST)
        self.user = patch.object(m.pwd, "getpwnam", return_value=type("PW", (), {"pw_uid": os.geteuid()})())
        self.host.start()
        self.user.start()
        self.addCleanup(self.host.stop)
        self.addCleanup(self.user.stop)

    def call(self, request=None, authority=None):
        return m.execute(request or self.request, self.a if authority is None else authority, workspace=self.base)

    def test_create_replace_cas_audit_and_replay(self):
        ok, report = self.call()
        self.assertTrue(ok, report)
        self.assertEqual(report["sha256"], hashlib.sha256(self.request["content_utf8"].encode()).hexdigest())
        self.assertEqual((self.base / "status.json").read_text(), '{"ok":true}')
        self.assertEqual((self.base / "status.json").stat().st_mode & 0o777, 0o600)
        ok, reason = self.call()
        self.assertFalse(ok)
        self.assertEqual(reason["reason"], "compare_and_swap_mismatch")
        nextreq = dict(self.request, content_utf8="updated", expected_sha256=report["sha256"],
                       idempotency_key="secondwrite-0002", fencing_token=2)
        ok, _ = self.call(nextreq)
        self.assertTrue(ok)
        self.assertEqual((self.base / "status.json").read_text(), "updated")
        self.assertEqual([x[0] for x in self.a.audit], ["prepared", "committed", "prepared", "committed"])
        ok, reason = self.call(dict(nextreq, expected_sha256=hashlib.sha256(b"updated").hexdigest()))
        self.assertEqual(reason["reason"], "lease_or_replay_denied")

    def test_denies_missing_authority_wrong_host_identity_and_grant(self):
        ok, reason = m.execute(self.request, None, workspace=self.base)
        self.assertFalse(ok)
        self.assertEqual(reason["reason"], "trusted_write_operator_unavailable")
        with patch.object(m.socket, "gethostname", return_value="backend"):
            self.assertEqual(self.call()[1]["reason"], "wrong_host")
        self.a.ok = False
        self.assertEqual(self.call()[1]["reason"], "owner_pr_grant_not_verified")
        self.assertEqual(list(self.base.iterdir()), [])

    def test_paths_symlinks_and_existing_private_permissions(self):
        for name in ("../../etc/passwd", "hidden/.env", ".env", "one.sh", "x" * 80 + ".txt", "a..b.txt"):
            bad = dict(self.request, filename=name)
            ok, _ = self.call(bad)
            self.assertFalse(ok)
        (self.base / "status.json").symlink_to("/etc/passwd")
        self.assertEqual(self.call()[1]["reason"], "protected_workspace_operation_failed")

    def test_rejects_wrong_directory_and_bad_cas_and_oversize(self):
        self.base.chmod(0o777)
        self.assertEqual(self.call()[1]["reason"], "protected_workspace_operation_failed")
        self.base.chmod(0o700)
        self.assertEqual(self.call(dict(self.request, expected_sha256="bad"))[1]["reason"], "invalid_compare_and_swap")
        self.assertEqual(self.call(dict(self.request, content_utf8="z" * 4097))[1]["reason"], "content_too_large")
        self.assertEqual(list(self.base.iterdir()), [])


class NoAuthorityContract(unittest.TestCase):
    def test_no_operator_fails_before_host_access(self):
        ok, reason = m.execute({}, None)
        self.assertFalse(ok)
        self.assertEqual(reason["reason"], "invalid_request_shape")


if __name__ == "__main__":
    unittest.main()
