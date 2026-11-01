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
          "merged_by": {"login": "shopvivaliz-human"}, "merge_commit_sha": MERGE,
          "head": {"sha": HEAD}, "base": {"ref": "main", "repo": {"full_name": REPO}},
          "auto_merge": None, "user": {"login": "fredmourao-ai"}}
    grant = {"schema": grant_module.SCHEMA, "repository": REPO, "pr_number": 123,
             "resource": "mcp:marina", "principal": "marina-mcp-service",
             "target_repository": "Vivaliz-site/site-shopvivaliz",
             "capabilities": ["file_write", "deployment_manage"],
             "expires_at_utc": "2026-12-01T00:00:00Z"}
    opts = dict(repository=REPO, pr_number=123, owner_login="shopvivaliz-human",
                merge_sha=MERGE, head_sha=HEAD, resource="mcp:marina",
                principal="marina-mcp-service", capability="file_write",
                target_repository="Vivaliz-site/site-shopvivaliz", now=TIME,
                reviews=[{"user": {"login": "shopvivaliz-human"}, "state": "APPROVED", "commit_id": HEAD}])
    return pr, grant, opts


class OwnerAuthorizationTests(unittest.TestCase):
    def test_security_policy_requires_owner_only_pr_for_security_grants(self):
        policy = (Path(__file__).resolve().parents[1]
                  / "docs/knowledge/security-approval-via-pr.md").read_text()
        self.assertIn('"operation": "olist_browser_restart"', policy)
        self.assertIn("shopvivaliz-human", policy)
        self.assertNotIn("chat aceito primeiro", policy.lower())
        self.assertNotIn("chat deve ser utilizada normalmente", policy.lower())

    def test_security_codeowners_are_only_human_account(self):
        codeowners = Path(__file__).resolve().parents[1] / ".github" / "CODEOWNERS"
        mapping = {}
        for line in codeowners.read_text().splitlines():
            parts = line.split()
            if parts and not parts[0].startswith("#"):
                mapping[parts[0]] = parts[1:]
        for path in (
            "/security/owner-approvals/",
            "/docs/knowledge/security-approval-via-pr.md",
            "/scripts/validate-owner-pr-grant.py",
        ):
            self.assertEqual(["@shopvivaliz-human"], mapping.get(path))


    def test_privileged_operation_must_bind_target_repository(self):
        pr, grant, opts = fixture()
        grant.update(operation="olist_browser_restart",
                     target_repository="Vivaliz-site/amazon-returns-safet")
        opts.update(capability="deployment_manage", operation="olist_browser_restart",
                    target_repository="Vivaliz-site/amazon-returns-safet")
        self.assertEqual((True, "owner_authorization_verified"),
                         grant_module.decide(pr, grant, **opts))
        opts["target_repository"] = "Vivaliz-site/mercadolivre-returns-recovery"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])
        opts["target_repository"] = None
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])
        opts["target_repository"] = "Vivaliz-site/amazon-returns-safet"
        del grant["target_repository"]
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_named_operation_grant_must_match_exact_execution(self):
        pr, grant, opts = fixture()
        grant["operation"] = "olist_browser_restart"
        grant["target_repository"] = "Vivaliz-site/amazon-returns-safet"
        opts["capability"] = "deployment_manage"
        opts["operation"] = "olist_browser_restart"
        opts["target_repository"] = "Vivaliz-site/amazon-returns-safet"
        self.assertEqual((True, "owner_authorization_verified"),
                         grant_module.decide(pr, grant, **opts))
        for wrong in ("other_service_restart", "shell", "", None):
            opts["operation"] = wrong
            self.assertFalse(grant_module.decide(pr, grant, **opts)[0], wrong)
        opts["operation"] = "olist_browser_restart"
        del grant["operation"]
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_unscoped_legacy_marina_deployment_grant_never_authorizes_restart(self):
        pr, grant, opts = fixture()
        opts["capability"] = "deployment_manage"
        self.assertEqual(
            (False, "privileged_operation_required"),
            grant_module.decide(pr, grant, **opts),
        )

    def test_operation_restricted_grant_denies_unscoped_readback(self):
        pr, grant, opts = fixture()
        grant["operation"] = "olist_browser_restart"
        opts["capability"] = "deployment_manage"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_file_write_security_grant_requires_repository_scope(self):
        pr, grant, opts = fixture()
        del grant["target_repository"]
        del opts["target_repository"]
        self.assertEqual(
            (False, "target_repository_not_approved"),
            grant_module.decide(pr, grant, **opts),
        )
        grant["target_repository"] = "Vivaliz-site/site-shopvivaliz"
        opts["target_repository"] = "Vivaliz-site/site-shopvivaliz"
        self.assertEqual(
            (True, "owner_authorization_verified"),
            grant_module.decide(pr, grant, **opts),
        )
        opts["target_repository"] = "Vivaliz-site/buscador"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_owner_merged_exact_scope_is_accepted(self):
        pr, grant, opts = fixture()
        self.assertEqual((True, "owner_authorization_verified"), grant_module.decide(pr, grant, **opts))

    def test_configured_owner_must_be_exclusive_human_account(self):
        pr, grant, opts = fixture()
        opts["owner_login"] = "fredmourao-ai"
        pr["merged_by"]["login"] = "fredmourao-ai"
        self.assertEqual(
            (False, "configured_owner_is_not_shopvivaliz_human"),
            grant_module.decide(pr, grant, **opts),
        )

    def test_human_account_must_not_author_own_security_authorization(self):
        pr, grant, opts = fixture()
        pr["user"] = {"login": "shopvivaliz-human"}
        self.assertEqual(
            (False, "security_pr_author_must_be_distinct"),
            grant_module.decide(pr, grant, **opts),
        )

    def test_missing_human_review_denies_security_grant(self):
        pr, grant, opts = fixture()
        opts["reviews"] = []
        self.assertEqual(
            (False, "security_pr_not_approved_by_human"),
            grant_module.decide(pr, grant, **opts),
        )

    def test_review_by_agent_does_not_authorize(self):
        pr, grant, opts = fixture()
        opts["reviews"] = [{"user": {"login": "fredmourao-ai"}, "state": "APPROVED", "commit_id": HEAD}]
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_review_for_previous_head_sha_denied(self):
        pr, grant, opts = fixture()
        opts["reviews"][0]["commit_id"] = "c" * 40
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_human_changes_requested_after_approval_revokes_grant(self):
        pr, grant, opts = fixture()
        opts["reviews"].append(
            {"user": {"login": "shopvivaliz-human"}, "state": "CHANGES_REQUESTED", "commit_id": HEAD}
        )
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_review_list_pagination_boundary_fails_closed(self):
        pr, grant, opts = fixture()
        opts["reviews"] *= 100
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_auto_merge_enabled_by_owner_is_not_human_only_grant(self):
        pr, grant, opts = fixture()
        pr["auto_merge"] = {
            "merge_method": "squash",
            "enabled_by": {"login": "shopvivaliz-human"},
        }
        self.assertEqual(
            (False, "owner_only_merge_not_proven_manual"),
            grant_module.decide(pr, grant, **opts),
        )

    def test_missing_auto_merge_metadata_fails_closed(self):
        pr, grant, opts = fixture()
        pr.pop("auto_merge")
        self.assertEqual(
            (False, "owner_only_merge_not_proven_manual"),
            grant_module.decide(pr, grant, **opts),
        )

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
        opts["owner_login"] = "SHOPVIVALIZ-HUMAN"
        self.assertTrue(grant_module.decide(pr, grant, **opts)[0])



    def test_central_marina_owner_grant_requires_exact_readonly_host_and_actor(self):
        pr, grant, opts = fixture()
        grant.update(resource="mcp:central:marina", principal="shopvivaliz-central-mcp",
                     host="marina-a1-4c24g",
                     controller_host="always-free-arm-1787907847-26",
                     capabilities=["marina_readonly"])
        del grant["target_repository"]
        opts.update(resource="mcp:central:marina", principal="shopvivaliz-central-mcp",
                    capability="marina_readonly", target_repository=None)
        self.assertEqual((True, "owner_authorization_verified"),
                         grant_module.decide(pr, grant, **opts))
        for field, replacement in (
            ("host", "shopvivaliz-free-a1"),
            ("host", "always-free-arm-1787907847-26"),
            ("controller_host", "shopvivaliz-free-a1"),
            ("principal", "root"),
            ("capabilities", ["marina_readonly", "admin_command_run"]),
            ("capabilities", ["file_write"]),
        ):
            changed = dict(grant, **{field: replacement})
            with self.subTest(field=field, replacement=replacement):
                self.assertFalse(grant_module.decide(pr, changed, **opts)[0])
        changed = dict(grant, operation="systemctl_restart")
        self.assertFalse(grant_module.decide(pr, changed, **opts)[0])
        changed = dict(grant, target_repository="Vivaliz-site/site-shopvivaliz")
        self.assertFalse(grant_module.decide(pr, changed, **opts)[0])
        opts["operation"] = "systemctl_restart"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_central_marina_grant_not_valid_for_privileged_marina_resource(self):
        pr, grant, opts = fixture()
        grant.update(resource="mcp:central:marina", principal="shopvivaliz-central-mcp",
                     host="marina-a1-4c24g", controller_host="always-free-arm-1787907847-26",
                     capabilities=["marina_readonly"])
        grant.pop("target_repository")
        opts.update(resource="mcp:marina", principal="shopvivaliz-central-mcp",
                    capability="marina_readonly", target_repository="Vivaliz-site/site-shopvivaliz")
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])
        opts.update(resource="mcp:central:marina", target_repository=None,
                    owner_login="fredmourao-ai")
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])


class GrantOriginBindingTests(unittest.TestCase):
    """Regression: approving an unrelated PR must never activate old grants."""

    def execute_gate(self, *, status="modified", diff_sha=None, files=None):
        import base64
        from contextlib import redirect_stdout
        from io import StringIO
        import json
        import os
        import sys
        from unittest.mock import patch

        pr, grant, opts = fixture()
        grant["expires_at_utc"] = "2099-01-01T00:00:00Z"
        path = "security/owner-approvals/123.json"
        blob_sha = "c" * 40
        blob = {
            "path": path, "sha": blob_sha, "encoding": "base64",
            "content": base64.b64encode(json.dumps(grant).encode("utf-8")).decode("ascii"),
        }
        if files is None:
            files = [{"filename": path, "sha": diff_sha or blob_sha, "status": status}]

        def read_github(url, _token):
            if url.endswith("/pulls/123"):
                return pr
            if url.endswith("/pulls/123/reviews?per_page=100"):
                return opts["reviews"]
            if url.endswith("/pulls/123/files?per_page=100"):
                return files
            if "/contents/" in url and "?ref=" in url:
                return dict(blob)
            raise AssertionError("unexpected GitHub endpoint in fixture")

        argv = [
            "validate-owner-pr-grant.py", "--repository", REPO,
            "--pr-number", "123", "--merge-sha", MERGE, "--head-sha", HEAD,
            "--resource", "mcp:marina", "--principal", "marina-mcp-service",
            "--capability", "file_write",
            "--target-repository", "Vivaliz-site/site-shopvivaliz",
        ]
        output = StringIO()
        with patch.dict(os.environ, {
                "SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "shopvivaliz-human",
                "GITHUB_TOKEN": "test-token-not-valid",
             }), patch.object(sys, "argv", argv), patch.object(
                grant_module, "github_json", side_effect=read_github
             ), redirect_stdout(output):
            exit_code = grant_module.main()
        return exit_code, output.getvalue()

    def test_agent_account_cannot_be_security_grant_owner(self):
        pr, grant, opts = fixture()
        opts["owner_login"] = "fredmourao-ai"
        pr["merged_by"]["login"] = "fredmourao-ai"
        self.assertFalse(grant_module.decide(pr, grant, **opts)[0])

    def test_preexisting_grant_in_unrelated_owner_pr_is_denied(self):
        exit_code, output = self.execute_gate(status="modified")
        self.assertEqual(exit_code, 1)
        self.assertIn("grant_not_added_by_reviewed_pr", output)

    def test_unlisted_grant_is_denied(self):
        exit_code, _ = self.execute_gate(files=[])
        self.assertEqual(exit_code, 1)

    def test_mismatched_head_blob_is_denied(self):
        exit_code, _ = self.execute_gate(status="added", diff_sha="d" * 40)
        self.assertEqual(exit_code, 1)

    def test_new_exact_grant_preserves_legitimate_authorization(self):
        exit_code, output = self.execute_gate(status="added")
        self.assertEqual(exit_code, 0, output)
        self.assertIn("OWNER_PR_GRANT=AUTHORIZED", output)

    def test_pr_file_list_at_pagination_boundary_fails_closed(self):
        files = [
            {"filename": f"docs/{i}.md", "status": "added", "sha": "f" * 40}
            for i in range(99)
        ]
        files.append({
            "filename": "security/owner-approvals/123.json",
            "status": "added", "sha": "c" * 40,
        })
        exit_code, output = self.execute_gate(files=files)
        self.assertEqual(exit_code, 1, output)

    def test_renamed_or_duplicate_grant_entries_are_rejected(self):
        path = "security/owner-approvals/123.json"
        blob = {"path": path, "sha": "c" * 40}
        entry = {"filename": path, "status": "added", "sha": blob["sha"]}
        self.assertFalse(grant_module.grant_added_in_reviewed_pr(
            [entry, entry], path, blob))
        self.assertFalse(grant_module.grant_added_in_reviewed_pr(
            [{**entry, "previous_filename": "old.json"}], path, blob))
        self.assertFalse(grant_module.grant_added_in_reviewed_pr(
            [{**entry, "status": "renamed"}], path, blob))

    def test_reject_wrong_blob_metadata_and_incomplete_diff(self):
        path = "security/owner-approvals/123.json"
        entry = {"filename": path, "status": "added", "sha": "c" * 40}
        self.assertFalse(grant_module.grant_added_in_reviewed_pr(
            [entry], path, {"path": "wrong.json", "sha": "c" * 40}))
        self.assertFalse(grant_module.grant_added_in_reviewed_pr(
            [entry], path, {"path": path}))
        self.assertFalse(grant_module.grant_added_in_reviewed_pr(
            "not-an-array", path, {"path": path, "sha": "c" * 40}))

    def test_99_files_with_exact_grant_remain_allowed(self):
        path = "security/owner-approvals/123.json"
        files = [
            {"filename": f"docs/{i}.md", "status": "added", "sha": "f" * 40}
            for i in range(98)
        ]
        files.append({"filename": path, "status": "added", "sha": "c" * 40})
        self.assertTrue(grant_module.grant_added_in_reviewed_pr(
            files, path, {"path": path, "sha": "c" * 40}))


# The existing Absolute Audit Governance workflow invokes this security suite.
# Reuse it to exercise every Marina adapter negative test without changing
# protected workflow YAML or enabling any runtime tool/permission.
MARINA_GATE_TEST_PATH = Path(__file__).resolve().parent / "test_marina_owner_pr_gate.py"
MARINA_GATE_SPEC = importlib.util.spec_from_file_location(
    "marina_owner_pr_security_tests", MARINA_GATE_TEST_PATH
)
assert MARINA_GATE_SPEC and MARINA_GATE_SPEC.loader
marina_gate_tests = importlib.util.module_from_spec(MARINA_GATE_SPEC)
MARINA_GATE_SPEC.loader.exec_module(marina_gate_tests)
MarinaOwnerPrGateTests = marina_gate_tests.OwnerPrGateTests


if __name__ == "__main__":
    unittest.main()
