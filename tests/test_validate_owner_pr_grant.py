from __future__ import annotations

from datetime import datetime, timezone
import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "validate-owner-pr-grant.py"
SPEC = importlib.util.spec_from_file_location("owner_pr_grant", SCRIPT)
grant_module = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(grant_module)

REPO = "Vivaliz-site/site-shopvivaliz"
HEAD = "a" * 40
MERGE = "b" * 40
TIME = datetime(2026, 10, 31, tzinfo=timezone.utc)


def fixture():
    pr = {"merged": True, "state": "closed", "number": 123,
          "merged_by": {"login": "verified-owner"}, "merge_commit_sha": MERGE,
          "head": {"sha": HEAD}, "base": {"ref": "main", "repo": {"full_name": REPO}}}
    grant = {"schema": grant_module.SCHEMA, "repository": REPO, "pr_number": 123,
             "resource": "mcp:marina", "principal": "marina-mcp-service",
             "capabilities": ["file_write", "deployment_manage"],
             "expires_at_utc": "2026-12-01T00:00:00Z"}
    opts = dict(repository=REPO, pr_number=123, owner_login="verified-owner",
                merge_sha=MERGE, head_sha=HEAD, resource="mcp:marina",
                principal="marina-mcp-service", capability="file_write", now=TIME)
    return pr, grant, opts


class OwnerAuthorizationTests(unittest.TestCase):
    def test_owner_merged_exact_scope_is_accepted(self):
        pr, grant, opts = fixture()
        self.assertEqual((True, "owner_authorization_verified"), grant_module.decide(pr, grant, **opts))

    def test_unmerged_pr_denied(self):
        pr, grant, opts = fixture()
        pr["merged"] = False
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_unauthorized_merger_denied(self):
        pr, grant, opts = fixture()
        pr["merged_by"]["login"] = "automation-bot"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_wrong_commit_denied(self):
        pr, grant, opts = fixture()
        pr["merge_commit_sha"] = "c" * 40
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_wrong_head_denied(self):
        pr, grant, opts = fixture()
        pr["head"]["sha"] = "c" * 40
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_wrong_target_denied(self):
        pr, grant, opts = fixture()
        pr["base"]["ref"] = "test"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_scope_escalation_denied(self):
        pr, grant, opts = fixture()
        opts["capability"] = "database_admin"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_resource_mismatch_denied(self):
        pr, grant, opts = fixture()
        opts["resource"] = "mcp:other"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_wrong_pr_number_denied(self):
        pr, grant, opts = fixture()
        grant["pr_number"] = 124
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_expired_grant_denied(self):
        pr, grant, opts = fixture()
        grant["expires_at_utc"] = "2026-01-01T00:00:00Z"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_duplicate_capabilities_denied(self):
        pr, grant, opts = fixture()
        grant["capabilities"] = ["file_write", "file_write"]
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_authorization_replacement_denied(self):
        original = {"sha": "a" * 40, "path": "security/owner-approvals/123.json"}
        current = {"sha": "b" * 40, "path": "security/owner-approvals/123.json"}
        self.assertFalse(grant_module.immutable_grant_still_active(original, current))

    def test_authorization_removal_denied(self):
        original = {"sha": "a" * 40, "path": "security/owner-approvals/123.json"}
        self.assertFalse(grant_module.immutable_grant_still_active(original, None))

    def test_unchanged_authorization_can_be_checked(self):
        original = {"sha": "a" * 40, "path": "security/owner-approvals/123.json"}
        self.assertTrue(grant_module.immutable_grant_still_active(original, dict(original)))

    def test_case_insensitive_owner_match(self):
        pr, grant, opts = fixture()
        opts["owner_login"] = "Verified-Owner"
        self.assertTrue(grant_module.decide(pr, grant, **opts)[0])


if __name__ == "__main__":
    unittest.main()
