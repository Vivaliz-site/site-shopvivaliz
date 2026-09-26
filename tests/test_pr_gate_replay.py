#!/usr/bin/env python3
import unittest
from pathlib import Path

from scripts.pr_gate_replay import (
    GATES,
    build_command,
    build_trusted_retrigger_command,
    build_trusted_retrigger_remote_script,
    build_dispatch_plan,
    is_replayable_state,
)


class PrGateReplayTest(unittest.TestCase):
    def setUp(self) -> None:
        self.common = {
            "repo": "Vivaliz-site/site-shopvivaliz",
            "head_ref": "fix/example-branch",
            "base_sha": "a" * 40,
            "head_sha": "b" * 40,
            "pr_labels": "repair-required-now,backend",
        }

    def test_exact_required_gate_mapping_and_context(self) -> None:
        self.assertEqual(
            list(GATES),
            [
                "Mandatory Validation Gate",
                "Quality Gate",
                "ShopVivaliz QA",
                "Repository Governance",
                "Policy Engine",
                "Autonomy Boundary",
                "History Integrity",
                "Ecommerce Excellence Audit",
                "PR Policy Enforcement",
            ],
        )

        policy = build_dispatch_plan("Policy Engine", **self.common)
        self.assertEqual(policy.workflow, "policy-engine.yml")
        self.assertEqual(
            dict(policy.inputs),
            {"base_sha": "a" * 40, "head_sha": "b" * 40},
        )

        autonomy = build_dispatch_plan("Autonomy Boundary", **self.common)
        self.assertEqual(autonomy.workflow, "autonomy-boundary.yml")
        self.assertEqual(autonomy.inputs["head_ref"], "fix/example-branch")
        self.assertEqual(autonomy.inputs["pr_labels"], "repair-required-now,backend")

        ecommerce = build_dispatch_plan("Ecommerce Excellence Audit", **self.common)
        self.assertEqual(dict(ecommerce.inputs), {"pr_replay": "true"})

        governance = build_dispatch_plan("Repository Governance", **self.common)
        self.assertEqual(
            dict(governance.inputs),
            {"base_sha": "a" * 40, "head_sha": "b" * 40},
        )

        mandatory = build_dispatch_plan("Mandatory Validation Gate", **self.common)
        self.assertEqual(mandatory.workflow, "mandatory-validation-gate.yml")
        self.assertEqual(dict(mandatory.inputs), {})

        quality = build_command("Quality Gate", **self.common)
        self.assertEqual(
            quality,
            [
                "gh",
                "workflow",
                "run",
                "quality-gate.yml",
                "--repo",
                "Vivaliz-site/site-shopvivaliz",
                "--ref",
                "fix/example-branch",
            ],
        )

    def test_mandatory_gate_uses_trusted_head_retrigger_instead_of_workflow_dispatch(self) -> None:
        remote = build_trusted_retrigger_remote_script(
            self.common["repo"],
            self.common["head_ref"],
            self.common["head_sha"],
        )
        self.assertIn("trusted-gate-retrigger", remote)
        self.assertIn("unset GH_TOKEN GITHUB_TOKEN", remote)
        self.assertIn("git/commits", remote)
        self.assertIn("git/refs/heads/", remote)
        self.assertNotIn("workflow run mandatory-validation-gate.yml", remote)

        command = build_trusted_retrigger_command(
            self.common["repo"],
            self.common["head_ref"],
            self.common["head_sha"],
            vm_host="127.0.0.1",
            vm_user="ubuntu",
            home="/home/ubuntu",
        )
        self.assertEqual(command[0], "ssh")
        self.assertIn("ubuntu@127.0.0.1", command)
        self.assertIn("/home/ubuntu/.ssh/id_rsa", command)
        self.assertIn("/home/ubuntu/.ssh/known_hosts", command)

    def test_bot_action_required_is_replayable_but_real_failures_are_not(self) -> None:
        self.assertTrue(is_replayable_state("missing"))
        self.assertTrue(is_replayable_state("completed:action_required"))
        self.assertFalse(is_replayable_state("completed:failure"))
        self.assertFalse(is_replayable_state("completed:cancelled"))
        self.assertFalse(is_replayable_state("in_progress:"))

        root = Path(__file__).resolve().parents[1]
        enforcer = (root / ".github" / "workflows" / "pr-completion-enforcer.yml").read_text()
        self.assertIn("missing|completed:action_required)", enforcer)
        self.assertIn("action_required_gate_replayed=true", enforcer)
        self.assertIn("failed_required_gate_auto_replayed=false", enforcer)

    def test_repository_governance_replay_preserves_pr_comparison_context(self) -> None:
        plan = build_dispatch_plan("Repository Governance", **self.common)
        self.assertEqual(
            dict(plan.inputs),
            {"base_sha": "a" * 40, "head_sha": "b" * 40},
        )

        workflow = (Path(__file__).resolve().parents[1] / ".github" / "workflows" / "repository-governance.yml").read_text()
        self.assertIn("base_sha:", workflow)
        self.assertIn("head_sha:", workflow)
        self.assertIn("REPLAY_BASE_SHA", workflow)
        self.assertIn("REPLAY_HEAD_SHA", workflow)

    def test_repository_governance_workflow_has_single_job_body(self) -> None:
        workflow = (Path(__file__).resolve().parents[1] / ".github" / "workflows" / "repository-governance.yml").read_text()
        self.assertEqual(workflow.count("- name: Compile governance and canonical entrypoints"), 1)
        self.assertEqual(workflow.count("- name: Audit token and secret references"), 1)
        self.assertEqual(workflow.count("- name: Upload governance evidence"), 1)

    def test_unknown_gate_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "unsupported required gate"):
            build_dispatch_plan("Unknown Gate", **self.common)


if __name__ == "__main__":
    unittest.main()
