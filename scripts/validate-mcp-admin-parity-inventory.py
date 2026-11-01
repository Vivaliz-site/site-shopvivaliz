#!/usr/bin/env python3
"""Validate the *discovery-only* cross-repository MCP administration inventory.

This is not an authorization provider, tool executor or host trust adapter.
Never infer operational readiness, tenant RBAC, SSH privilege or a grant here.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

SCHEMA = "SHOPVIVALIZ_MCP_ADMIN_PARITY_DISCOVERY_V1"
REPOS = frozenset({
    "Vivaliz-site/site-shopvivaliz",
    "Vivaliz-site/amazon-returns-safet",
    "Vivaliz-site/demo-repository",
    "Vivaliz-site/shopvivaliz-m365",
    "Vivaliz-site/mercadolivre-returns-recovery",
    "Vivaliz-site/buscador",
    "Vivaliz-site/ml-pricing-api",
    "Vivaliz-site/-shopvivaliz-pipeline",
    "fredmourao-ai/solange-rolla-consultorio",
    "fredmourao-ai/solange-rolla",
    "fredmourao-ai/mei-mg-email",
})
HOSTS = frozenset({
    "marina-a1-4c24g", "always-free-arm-1787907847-26",
    "github-platform", "UNRESOLVED",
})
KINDS = frozenset({"inspection", "deploy", "workflow", "service", "reconciliation", "backup", "security"})
RISKS = frozenset({"read", "mutation", "restricted"})
SHA = re.compile(r"^[0-9a-f]{40}$")
ACTION = re.compile(r"^[a-z][a-z0-9_]{5,70}$")
FORBIDDEN = frozenset({"admin_command_run", "shell", "sudo", "exec", "root_access"})


def validate(document: object) -> tuple[bool, str]:
    if not isinstance(document, dict):
        return False, "invalid_document"
    if set(document) != {"schema", "execution_state", "repositories", "captured_date"}:
        return False, "invalid_schema_fields"
    if document["schema"] != SCHEMA or document["execution_state"] != "DISCOVERY_ONLY":
        return False, "discovery_must_not_authorize"
    repos = document["repositories"]
    if not isinstance(repos, list) or len(repos) != len(REPOS):
        return False, "missing_repositories"
    seen_repos = set()
    seen_actions = set()
    for row in repos:
        if not isinstance(row, dict) or set(row) != {
            "repository", "main_sha", "workflow_count", "source_file_count",
            "execution_state", "candidate_actions",
        }:
            return False, "invalid_repo_record"
        repo = row["repository"]
        if not isinstance(repo, str) or repo not in REPOS or repo in seen_repos:
            return False, "unexpected_repository"
        seen_repos.add(repo)
        if not isinstance(row["main_sha"], str) or not SHA.fullmatch(row["main_sha"]):
            return False, "invalid_commit_identity"
        if row["execution_state"] != "NOT_CERTIFIED":
            return False, "uncertified_repo_marked_ready"
        if type(row["workflow_count"]) is not int or row["workflow_count"] < 0:
            return False, "invalid_workflow_count"
        if type(row["source_file_count"]) is not int or row["source_file_count"] < 1:
            return False, "invalid_source_count"
        actions = row["candidate_actions"]
        if not isinstance(actions, list):
            return False, "invalid_candidates"
        if (repo == "Vivaliz-site/demo-repository") != (len(actions) == 0):
            return False, "unexpected_candidate_coverage"
        for op in actions:
            if not isinstance(op, dict) or set(op) != {
                "action_id", "source", "host", "kind", "risk", "authorization", "executable",
            }:
                return False, "invalid_action_record"
            aid = op["action_id"]
            if (not isinstance(aid, str) or not ACTION.fullmatch(aid) or
                    aid in FORBIDDEN or aid in seen_actions):
                return False, "invalid_action_identity"
            seen_actions.add(aid)
            source = op["source"]
            if (not isinstance(source, str) or not source or source.startswith("/")
                    or "\\" in source or "\x00" in source or
                    any(part in ("", ".", "..") for part in source.split("/"))):
                return False, "invalid_source"
            if (any(not isinstance(op[k], str) for k in ("host", "kind", "risk")) or
                    op["host"] not in HOSTS or op["kind"] not in KINDS or
                    op["risk"] not in RISKS):
                return False, "invalid_routing_or_risk"
            if op["authorization"] != "NOT_PROVISIONED" or op["executable"] is not False:
                return False, "unauthorized_routine_activation"
    if seen_repos != REPOS:
        return False, "missing_repository"
    return True, "valid_discovery_only_inventory"


if __name__ == "__main__":
    p = Path(__file__).resolve().parents[1] / "docs/ops/mcp-admin-repositories-2026-11-01.json"
    try:
        value = json.loads(p.read_text(encoding="utf-8"))
        ok, reason = validate(value)
    except (OSError, ValueError):
        ok, reason = False, "inventory_unavailable"
    print(f"MCP_ADMIN_PARITY_INVENTORY={'PASS' if ok else 'FAIL'} reason={reason}")
    raise SystemExit(0 if ok else 1)
