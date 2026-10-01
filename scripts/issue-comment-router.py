#!/usr/bin/env python3
"""Classify a GitHub issue_comment into exactly one ShopVivaliz workflow route."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

AUTHORIZED_USER = "fredmourao-ai"
CONTROL_ISSUE = 1586

EXACT_CONTROL_ROUTES = {
    "/refresh-backend-delete-repo-scope-v1 CONFIRM": "refresh_backend_delete_scope",
    "/delete-superseded-solange-v1 CONFIRM": "delete_superseded_solange",
    "/continuity-e2e": "continuity_e2e",
    "/backend-continuity-recover-v1": "backend_continuity_recovery",
    "/provision-governed-backend-ci-runners-v1": "provision_backend_runners",
    "/refresh-a1-delete-repo-scope-v1 CONFIRM": "refresh_a1_delete_scope",
    "/codex-remote-control-mcp-run": "codex_remote_control",
    "/secure-mcp-runtime-recover": "secure_mcp_runtime_recovery",
    "/codex-global-issue-comment-routing-v1": "codex_global_issue_comment_routing",
    "/amazon-support-reply case_ids=22153077391,22153259501": "amazon_support_breakglass",
    "/amazon-support-readback case_ids=22153077391,22153259501": "amazon_support_breakglass",
    "/amazon-support-chat-reply case_ids=22199842931": "amazon_support_breakglass",
}


def classify(payload: dict[str, Any]) -> str:
    comment = payload.get("comment") or {}
    issue = payload.get("issue") or {}
    actor = str((comment.get("user") or {}).get("login") or "")
    body = str(comment.get("body") or "")
    issue_number = issue.get("number")

    if actor != AUTHORIZED_USER:
        return "none"

    if issue_number == CONTROL_ISSUE:
        exact = EXACT_CONTROL_ROUTES.get(body.strip())
        if exact:
            return exact

        stripped = body.strip()
        if stripped.startswith("/dc-reauth "):
            return "dc_reauth"
        if stripped.startswith("/remote "):
            return "remote_access"
        if stripped.startswith("/mlrr "):
            return "mlrr"
        if stripped.startswith("/codex-auto-continuity-v7-goal "):
            return "codex_continuity_goal"

        first_line = body.splitlines()[0].strip() if body.splitlines() else ""
        if first_line == "/codex-auto-continuity-v7":
            return "codex_continuity_launcher"

    # @claude remains available on any issue/PR comment by the authorized user,
    # but only after all explicit slash commands have been classified.
    if "@claude" in body:
        return "claude"

    return "none"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--event", required=True)
    parser.add_argument("--output")
    args = parser.parse_args()

    payload = json.loads(Path(args.event).read_text(encoding="utf-8"))
    route = classify(payload)

    if args.output:
        with Path(args.output).open("a", encoding="utf-8") as handle:
            handle.write(f"route={route}\n")

    print(f"ISSUE_COMMENT_ROUTE={route}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
