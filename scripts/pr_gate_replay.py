#!/usr/bin/env python3
"""Dispatch one recoverable required PR gate on the exact PR head ref."""

from __future__ import annotations

import argparse
import re
import subprocess
from dataclasses import dataclass
from typing import Mapping

_SHA_RE = re.compile(r"^[0-9a-f]{40}$")
_REF_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$")
_REPO_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")


@dataclass(frozen=True)
class GateDispatch:
    workflow: str
    inputs: Mapping[str, str]


GATES: dict[str, str] = {
    "Quality Gate": "quality-gate.yml",
    "ShopVivaliz QA": "shopvivaliz-qa.yml",
    "Repository Governance": "repository-governance.yml",
    "Policy Engine": "policy-engine.yml",
    "Autonomy Boundary": "autonomy-boundary.yml",
    "History Integrity": "history-integrity.yml",
    "Ecommerce Excellence Audit": "ecommerce-excellence-audit.yml",
    "PR Policy Enforcement": "pr-policy-enforcement.yml",
}


def is_replayable_state(state: str) -> bool:
    """Only recover states where the required gate did not actually execute."""
    return state in {"missing", "completed:action_required"}


def _validate(repo: str, head_ref: str, base_sha: str, head_sha: str) -> None:
    if not _REPO_RE.fullmatch(repo):
        raise ValueError("invalid repository")
    if not _REF_RE.fullmatch(head_ref) or ".." in head_ref or "@{" in head_ref:
        raise ValueError("invalid head ref")
    if not _SHA_RE.fullmatch(base_sha):
        raise ValueError("invalid base SHA")
    if not _SHA_RE.fullmatch(head_sha):
        raise ValueError("invalid head SHA")


def build_dispatch_plan(
    gate: str,
    repo: str,
    head_ref: str,
    base_sha: str,
    head_sha: str,
    pr_labels: str = "",
) -> GateDispatch:
    _validate(repo, head_ref, base_sha, head_sha)
    workflow = GATES.get(gate)
    if workflow is None:
        raise ValueError(f"unsupported required gate: {gate}")

    inputs: dict[str, str] = {}
    if gate == "Policy Engine":
        inputs = {"base_sha": base_sha, "head_sha": head_sha}
    elif gate == "Autonomy Boundary":
        inputs = {
            "base_sha": base_sha,
            "head_sha": head_sha,
            "head_ref": head_ref,
            "pr_labels": pr_labels,
        }
    elif gate == "Ecommerce Excellence Audit":
        inputs = {"pr_replay": "true"}

    return GateDispatch(workflow=workflow, inputs=inputs)


def build_command(
    gate: str,
    repo: str,
    head_ref: str,
    base_sha: str,
    head_sha: str,
    pr_labels: str = "",
) -> list[str]:
    plan = build_dispatch_plan(gate, repo, head_ref, base_sha, head_sha, pr_labels)
    command = ["gh", "workflow", "run", plan.workflow, "--repo", repo, "--ref", head_ref]
    for key, value in plan.inputs.items():
        command.extend(["-f", f"{key}={value}"])
    return command


def dispatch(**kwargs: str) -> None:
    command = build_command(**kwargs)
    subprocess.run(command, check=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--gate", required=True)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--head-ref", required=True)
    parser.add_argument("--base-sha", required=True)
    parser.add_argument("--head-sha", required=True)
    parser.add_argument("--pr-labels", default="")
    args = parser.parse_args()

    dispatch(
        gate=args.gate,
        repo=args.repo,
        head_ref=args.head_ref,
        base_sha=args.base_sha,
        head_sha=args.head_sha,
        pr_labels=args.pr_labels,
    )
    print(f"gate_replay_dispatched={args.gate}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
