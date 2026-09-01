#!/usr/bin/env python3
import unittest

from scripts.pr_gate_replay import (
    GATES,
    build_command,
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

    def test_bot_action_required_is_replayable_but_real_failures_are_not(self) -> None:
        self.assertTrue(is_replayable_state("missing"))
        self.assertTrue(is_replayable_state("completed:action_required"))
        self.assertFalse(is_replayable_state("completed:failure"))
        self.assertFalse(is_replayable_state("completed:cancelled"))
        self.assertFalse(is_replayable_state("in_progress:"))

    def test_unknown_gate_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "unsupported required gate"):
            build_dispatch_plan("Unknown Gate", **self.common)


if __name__ == "__main__":
    unittest.main()
