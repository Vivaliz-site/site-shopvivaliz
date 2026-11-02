"""Real owner grant, durable replay and audit contract for Marina private writes."""
from __future__ import annotations

import base64
import hashlib
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "trusted_write_operator.py"
spec = importlib.util.spec_from_file_location("trusted_write_operator", SOURCE)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class OperatorTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.workspace = Path(self.temp.name) / "workspace"
        self.state = Path(self.temp.name) / "state"
        for p in (self.workspace, self.state):
            p.mkdir(mode=0o700)
            p.chmod(0o700)
        self.user = patch.object(m.pwd, "getpwnam", return_value=type("U", (), {"pw_uid": os.geteuid()})())
        self.host = patch.object(m.socket, "gethostname", return_value=m.HOST)
        self.user.start()
        self.host.start()
        self.addCleanup(self.user.stop)
        self.addCleanup(self.host.stop)
        self.operator = m.ProtectedWorkspaceOperator(workspace=self.workspace, state=self.state)
        self.proof_cache = patch.object(m, "_STATIC_PROOF", None)
        self.proof_cache.start()
        self.addCleanup(self.proof_cache.stop)
        self.token_path = Path(self.temp.name) / "github-read-token"
        self.token_setting = patch.object(m, "GITHUB_READ_TOKEN_FILE", self.token_path)
        self.token_setting.start()
        self.addCleanup(self.token_setting.stop)

    def test_durable_lease_replay_and_audit_append(self):
        if os.geteuid() == 0:
            self.skipTest("nonroot test; runtime must never write as root")
        self.assertTrue(self.operator.runtime_permission_valid())
        self.assertTrue(self.operator.reserve_lease_once(m.OPERATION, "write-first-00001", 1))
        self.assertFalse(self.operator.reserve_lease_once(m.OPERATION, "write-first-00001", 1))
        self.assertFalse(self.operator.reserve_lease_once(m.OPERATION, "write-stale-0001", 1))
        second = m.ProtectedWorkspaceOperator(workspace=self.workspace, state=self.state)
        self.assertTrue(second.reserve_lease_once(m.OPERATION, "write-second-0002", 2))
        self.assertTrue(second.audit_prepared(m.OPERATION, "write-second-0002", "status.json", "a"*64))
        self.assertTrue(second.audit_committed(m.OPERATION, "write-second-0002", "status.json", "a"*64))
        lines = (self.state/"write-audit.jsonl").read_text().splitlines()
        self.assertEqual([json.loads(row)["event"] for row in lines], ["prepared", "committed"])
        self.assertNotIn("secret", "\n".join(lines))
        self.assertEqual((self.state/"write-audit.jsonl").stat().st_mode & 0o777, 0o600)

    def test_directory_symlink_and_root_identity_refused(self):
        if os.geteuid() == 0:
            self.skipTest("nonroot test")
        self.state.chmod(0o777)
        self.assertFalse(self.operator.runtime_permission_valid())
        self.state.chmod(0o700)
        alias = Path(self.temp.name)/"alias"
        alias.symlink_to(self.workspace, target_is_directory=True)
        other = m.ProtectedWorkspaceOperator(workspace=alias, state=self.state)
        self.assertFalse(other.runtime_permission_valid())

    def fixture(self, *, approved=True, revoked=False, extra_cap=False, expired=False):
        grant = {
            "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
            "repository": m.REPOSITORY,
            "pr_number": 3514,
            "resource": "mcp:marina",
            "principal": "marina-mcp-service",
            "target_repository": m.REPOSITORY,
            "host": m.HOST,
            "capabilities": ["file_write"] + (["service_restart"] if extra_cap else []),
            "operation": m.OPERATION,
            "workspace_root": str(m.WORKSPACE),
            "allowed_extensions": [".json", ".txt"],
            "max_content_bytes": 4096,
            "cas_required": True,
            "lease_fencing_required": True,
            "audit_required": True,
            "root_or_shell_allowed": False,
            "expires_at_utc": "2026-01-01T00:00:00Z" if expired else "2026-12-02T03:49:00Z",
        }
        raw = base64.b64encode(json.dumps(grant).encode()).decode()
        sha = hashlib.sha1(raw.encode()).hexdigest()
        blob = {"encoding": "base64", "content": raw, "sha": sha, "path": m.GRANT_PATH}
        pr = {"merged": True, "state": "closed", "number": 3514,
              "auto_merge": None,
              "merged_by": {"login": "shopvivaliz-human" if approved else "some-bot"},
              "user": {"login": "fredmourao-ai"},
              "head": {"sha": m.GRANT_HEAD},
              "base": {"ref": "main", "repo": {"full_name": m.REPOSITORY}},
              "merge_commit_sha": m.GRANT_MERGE}
        review = [{"state": "APPROVED", "user": {"login": "shopvivaliz-human"},
                   "commit_id": m.GRANT_HEAD}]
        files = [{"filename": m.GRANT_PATH, "status": "added", "sha": sha}]
        current = dict(blob)
        if revoked:
            current["sha"] = "0" * 40
        return (pr, files, blob, review, current)

    def test_fresh_owner_approval_and_revocation(self):
        if os.geteuid() == 0:
            self.skipTest("nonroot test")
        args = (3514, m.GRANT_HEAD, m.GRANT_MERGE, m.CAPABILITY, m.OPERATION, m.REPOSITORY)
        with patch.object(m, "_public_api", side_effect=self.fixture()):
            self.assertTrue(self.operator.grant_valid(*args))
        with patch.object(m, "_STATIC_PROOF", None):
            with patch.object(m, "_public_api", side_effect=self.fixture(approved=False)):
                self.assertFalse(self.operator.grant_valid(*args))
        with patch.object(m, "_STATIC_PROOF", None):
            with patch.object(m, "_public_api", side_effect=self.fixture(revoked=True)):
                self.assertFalse(self.operator.grant_valid(*args))
        with patch.object(m, "_STATIC_PROOF", None):
            with patch.object(m, "_public_api", side_effect=self.fixture(extra_cap=True)):
                self.assertFalse(self.operator.grant_valid(*args))
        with patch.object(m, "_STATIC_PROOF", None):
            with patch.object(m, "_public_api", side_effect=self.fixture(expired=True)):
                self.assertFalse(self.operator.grant_valid(*args))
        with patch.object(m, "_public_api") as network:
            self.assertFalse(self.operator.grant_valid(3515, *args[1:]))
            network.assert_not_called()

    def test_historical_proof_cache_keeps_reviews_and_main_fresh(self):
        args = (3514, m.GRANT_HEAD, m.GRANT_MERGE, m.CAPABILITY, m.OPERATION, m.REPOSITORY)
        first = self.fixture()
        with patch.object(m, "_public_api", side_effect=first + (first[3], first[4])) as fetch:
            self.assertTrue(self.operator.grant_valid(*args))
            self.assertTrue(self.operator.grant_valid(*args))
            self.assertEqual(fetch.call_count, 7)
            self.assertIn("/reviews", fetch.call_args_list[-2].args[0])
            self.assertTrue(fetch.call_args_list[-1].args[0].endswith("?ref=main"))
        dismissed = self.fixture()
        dismissed[3][0]["state"] = "DISMISSED"
        with patch.object(m, "_public_api", side_effect=(dismissed[3], dismissed[4])) as fetch:
            self.assertFalse(self.operator.grant_valid(*args))
            self.assertEqual(fetch.call_count, 2)
        revoked = self.fixture(revoked=True)
        with patch.object(m, "_public_api", side_effect=(revoked[3], revoked[4])) as fetch:
            self.assertFalse(self.operator.grant_valid(*args))
            self.assertEqual(fetch.call_count, 2)

    def test_optional_private_github_token_metadata_and_symlink(self):
        self.assertIsNone(m._read_protected_github_token())
        sample = "github_pat_" + "a" * 76
        self.token_path.write_text(sample, encoding="ascii")
        self.token_path.chmod(0o600)
        self.assertEqual(m._read_protected_github_token(), sample)
        Path(self.temp.name).chmod(0o755)
        with self.assertRaises(RuntimeError):
            m._read_protected_github_token()
        Path(self.temp.name).chmod(0o700)
        self.token_path.chmod(0o644)
        with self.assertRaises(RuntimeError):
            m._read_protected_github_token()
        self.token_path.chmod(0o600)
        self.token_path.unlink()
        target = Path(self.temp.name) / "target"
        target.write_text(sample, encoding="ascii")
        self.token_path.symlink_to(target)
        with self.assertRaises(OSError):
            m._read_protected_github_token()

    def test_private_token_used_only_for_pinned_api_without_leak(self):
        from unittest.mock import Mock
        sample = "github_pat_" + "x" * 76
        self.token_path.write_text(sample + "\n", encoding="ascii")
        self.token_path.chmod(0o600)
        response = Mock(status=200)
        response.read.return_value = b'{"ok":true}'
        manager = Mock()
        manager.__enter__ = Mock(return_value=response)
        manager.__exit__ = Mock(return_value=None)
        with patch.object(m, "urlopen", return_value=manager) as api:
            self.assertEqual(m._public_api("/repos/Vivaliz-site/site-shopvivaliz/pulls/3514"), {"ok": True})
            headers = dict(api.call_args.args[0].header_items())
            self.assertEqual(headers.get("Authorization"), "Bearer " + sample)
        with self.assertRaises(RuntimeError):
            m._public_api("/repos/another-user/another-repo/pulls/3514")

    def test_network_failure_and_expired_grant_fail_closed(self):
        if os.geteuid() == 0:
            self.skipTest("nonroot test")
        args = (3514, m.GRANT_HEAD, m.GRANT_MERGE, m.CAPABILITY, m.OPERATION, m.REPOSITORY)
        with patch.object(m, "_public_api", side_effect=TimeoutError("github_timeout")):
            self.assertFalse(self.operator.grant_valid(*args))
        # A different PR number is denied before any network request.
        with patch.object(m, "_public_api") as api:
            self.assertFalse(self.operator.grant_valid(0, *args[1:]))
            api.assert_not_called()

if __name__ == "__main__":
    unittest.main()
