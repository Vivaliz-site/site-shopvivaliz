#!/usr/bin/env python3
"""Fail-closed verification of read-only GitHub workflow source reconciliation.

This document is evidence about registered paths versus repository main; it
is not a workflow dispatch allowlist, administrative grant, or execution engine.
"""
from __future__ import annotations
import json
import re
from pathlib import Path

SCHEMA = "SHOPVIVALIZ_MCP_WORKFLOW_SOURCE_AUDIT_V1"
SHA = re.compile(r"^[0-9a-f]{40}$")
WORKFLOW = re.compile(r"^\.github/workflows/[A-Za-z0-9_.-]+\.ya?ml$")


def validate(doc: object, *, expected_repositories: set[str]) -> tuple[bool, str]:
    if not isinstance(doc, dict) or set(doc) != {
        "schema", "captured_date", "execution_state", "repositories"
    }:
        return False, "invalid_document"
    if doc["schema"] != SCHEMA or doc["execution_state"] != "DISCOVERY_ONLY":
        return False, "snapshot_cannot_authorize"
    if not isinstance(doc["captured_date"], str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", doc["captured_date"]):
        return False, "invalid_capture_date"
    repos = doc["repositories"]
    if not isinstance(repos, list) or len(repos) != len(expected_repositories):
        return False, "repository_count_mismatch"
    numbers = ("registered_workflows_total", "registered_paths_under_workflows",
               "registered_active_under_workflows", "main_workflow_files_total")
    lists = ("active_registered_in_main_paths", "active_registered_not_in_main_paths",
             "inactive_registered_in_main_paths", "main_not_registered_paths")
    seen = set()
    for row in repos:
        if not isinstance(row, dict) or set(row) != {
            "repository", "main_sha", "execution_state", "mcp_executable", *numbers, *lists
        }:
            return False, "invalid_repo_row"
        repo = row["repository"]
        if not isinstance(repo, str) or repo not in expected_repositories or repo in seen:
            return False, "repository_invalid"
        seen.add(repo)
        if not isinstance(row["main_sha"], str) or not SHA.fullmatch(row["main_sha"]):
            return False, "invalid_main_sha"
        if row["execution_state"] != "NOT_CERTIFIED" or row["mcp_executable"] is not False:
            return False, "unverified_execution_claim"
        if any(type(row[k]) is not int or row[k] < 0 for k in numbers):
            return False, "invalid_counts"
        if any(not isinstance(row[k], list) for k in lists):
            return False, "invalid_path_list"
        groups = []
        for k in lists:
            paths = row[k]
            if any(not isinstance(p, str) or not WORKFLOW.fullmatch(p) for p in paths):
                return False, "invalid_workflow_path"
            if len(paths) != len(set(paths)):
                return False, "duplicate_workflow"
            groups.append(set(paths))
        if any(groups[i] & groups[j] for i in range(4) for j in range(i + 1, 4)):
            return False, "workflow_in_multiple_states"
        if row["main_workflow_files_total"] != len(groups[0] | groups[2] | groups[3]):
            return False, "main_count_mismatch"
        if row["registered_active_under_workflows"] != len(groups[0] | groups[1]):
            return False, "active_count_mismatch"
        if (row["registered_paths_under_workflows"] < row["registered_active_under_workflows"] + len(groups[2])
                or row["registered_workflows_total"] < row["registered_paths_under_workflows"]):
            return False, "registered_count_mismatch"
    if seen != expected_repositories:
        return False, "coverage_gap"
    return True, "valid_discovery_only_workflow_audit"


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[1]
    try:
        catalog = json.loads((root / "docs/ops/mcp-admin-repositories-2026-11-01.json").read_text())
        expected = {row["repository"] for row in catalog["repositories"]}
        audit = json.loads((root / "docs/ops/mcp-admin-workflow-source-audit-2026-11-01.json").read_text())
        ok, reason = validate(audit, expected_repositories=expected)
    except (ValueError, TypeError, KeyError, OSError):
        ok, reason = False, "source_unavailable"
    print(f"MCP_ADMIN_WORKFLOW_AUDIT={'PASS' if ok else 'FAIL'} reason={reason}")
    raise SystemExit(0 if ok else 1)
