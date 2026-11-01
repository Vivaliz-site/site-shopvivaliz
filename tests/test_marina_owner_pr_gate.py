#!/usr/bin/env python3
"""Negative-first regression tests for the staging-only Marina PR gate."""
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "owner_pr_gate.py"
SPEC = importlib.util.spec_from_file_location("marina_owner_pr_gate", SOURCE)
assert SPEC and SPEC.loader
gate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)

PR = 4567
HEAD = "a" * 40
MERGE = "b" * 40


class OwnerPrGateTests(unittest.TestCase):
    def setUp(self):
        self.verifier_calls = []
        self.proofs = []

        def runner(command, **kwargs):
            self.verifier_calls.append((command, kwargs))
            return SimpleNamespace(returncode=0, stdout=gate.VERIFIER_SUCCESS + "\n")

        def human(pr, head, merge):
            self.proofs.append(("human", pr, head, merge))
            return True

        def privilege(cap):
            self.proofs.append(("runtime", cap))
            return True

        self.runner = runner
        self.human = human
        self.privilege = privilege
        self.args = dict(
            pr_number=PR, head_sha=HEAD, merge_sha=MERGE,
            capability="file_write", runner=runner,
        )
        self.environ = patch.dict(os.environ, {
            "SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "verified-owner",
            "GITHUB_TOKEN": "test-only-unusable",
        })
        self.environ.start()
        self.addCleanup(self.environ.stop)

    def verify(self, **changes):
        opts = dict(self.args)
        opts.update(changes)
        return gate.authorize_owner_pr(**opts)


    def test_olist_deployment_grant_requires_exact_operation(self):
        proof = dict(capability="deployment_manage",
                     human_attestor=self.human,
                     runtime_permission=self.privilege)
        self.assertEqual(
            self.verify(**proof), (False, "operation_required_for_deployment"),
        )
        self.assertEqual(self.verifier_calls, [])
        self.assertEqual(
            self.verify(**proof, operation="olist_browser_restart"),
            (True, "exact_owner_pr_grant_verified"),
        )
        command = self.verifier_calls[-1][0]
        self.assertEqual(command[command.index("--operation") + 1], "olist_browser_restart")
        for value in ("shell", "other_service_restart", "", None, 123, [], {}):
            self.assertFalse(self.verify(**proof, operation=value)[0], value)

    def test_no_implicit_approval_from_merged_pr_or_owner_login(self):
        self.assertEqual(self.verify(), (False, "human_only_attestation_unavailable"))
        self.assertEqual(self.verifier_calls, [])

    def test_external_attestor_required_not_an_environment_flag(self):
        with patch.dict(os.environ, {"SHOPVIVALIZ_OWNER_APPROVED": "true"}):
            self.assertFalse(self.verify()[0])
        self.assertFalse(self.verifier_calls)

    def test_runtime_permission_is_independent(self):
        self.assertEqual(self.verify(human_attestor=self.human),
                         (False, "runtime_permission_unavailable"))
        self.assertFalse(self.verifier_calls)

    def test_attestation_denial(self):
        self.assertEqual(self.verify(human_attestor=lambda *_: False,
                                     runtime_permission=self.privilege),
                         (False, "exclusive_human_approval_not_proven"))
        self.assertFalse(self.verifier_calls)

    def test_runtime_denial(self):
        self.assertEqual(self.verify(human_attestor=self.human,
                                     runtime_permission=lambda *_: False),
                         (False, "runtime_permission_denied"))
        self.assertFalse(self.verifier_calls)

    def test_exact_positive_path_only_when_all_guards_supplied(self):
        self.assertEqual(self.verify(human_attestor=self.human,
                                     runtime_permission=self.privilege),
                         (True, "exact_owner_pr_grant_verified"))
        self.assertEqual(len(self.verifier_calls), 1)
        command, opts = self.verifier_calls[0]
        self.assertEqual(command[command.index("--resource") + 1], "mcp:marina")
        self.assertEqual(command[command.index("--capability") + 1], "file_write")
        self.assertEqual(command[command.index("--principal") + 1], "marina-mcp-service")
        self.assertEqual(opts["timeout"], 20)
        self.assertEqual(self.proofs[0], ("human", PR, HEAD, MERGE))

    def test_no_relaxed_matching_of_verifier_output(self):
        wrong = lambda *args, **kwargs: SimpleNamespace(
            returncode=0, stdout=gate.VERIFIER_SUCCESS + " extra\n",
        )
        self.assertEqual(self.verify(human_attestor=self.human,
                                     runtime_permission=self.privilege, runner=wrong),
                         (False, "owner_pr_not_verified"))

    def test_verifier_failure_cannot_be_overridden(self):
        bad = lambda *args, **kwargs: SimpleNamespace(returncode=1, stdout=gate.VERIFIER_SUCCESS)
        self.assertFalse(self.verify(human_attestor=self.human,
                                     runtime_permission=self.privilege, runner=bad)[0])

    def test_bad_pr_or_sha_or_capability_is_rejected_before_callbacks(self):
        for change in ({"pr_number": 0}, {"pr_number": True},
                       {"head_sha": "bad"}, {"merge_sha": "bad"},
                       {"capability": "admin_command_run"},
                       {"capability": "sudo"},
                       {"capability": None}):
            with self.subTest(change=change):
                self.assertEqual(self.verify(human_attestor=self.human,
                                             runtime_permission=self.privilege,
                                             **change)[0], False)
        self.assertFalse(self.verifier_calls)

    def test_missing_protected_identity_or_token(self):
        with patch.dict(os.environ, {"SHOPVIVALIZ_SECURITY_OWNER_LOGIN": "", "GITHUB_TOKEN": ""}):
            self.assertEqual(self.verify(human_attestor=self.human,
                                         runtime_permission=self.privilege),
                             (False, "trusted_verifier_configuration_missing"))

    def test_attestor_errors_do_not_grant_permission(self):
        def broken(*_):
            raise RuntimeError("unavailable")
        self.assertEqual(self.verify(human_attestor=broken,
                                     runtime_permission=self.privilege),
                         (False, "trusted_authorization_probe_failed"))
        self.assertFalse(self.verifier_calls)

    def test_no_privileged_tools_appear_in_existing_mcp_server(self):
        server_source = SOURCE.with_name("server.py").read_text(encoding="utf-8")
        self.assertNotIn('"marina_file_write"', server_source)
        self.assertNotIn('"marina_database_admin"', server_source)
        self.assertNotIn('"marina_deployment_manage"', server_source)


if __name__ == "__main__":
    unittest.main()
