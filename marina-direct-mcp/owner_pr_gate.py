#!/usr/bin/env python3
"""Fail-closed owner-PR gate for a *future* Marina MCP mutation surface.

This module does NOT expose an MCP tool, grant host privileges, or activate a
service. A privileged handler may use it only after its own RBAC, lease and
fencing implementation has been independently reviewed.

The human-only attestor and runtime permission check MUST be trusted host-side
implementations, never values supplied by the MCP caller or environment flags.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Callable

REPOSITORY = "Vivaliz-site/site-shopvivaliz"
RESOURCE = "mcp:marina"
PRINCIPAL = "marina-mcp-service"
CAPABILITIES = frozenset({"file_write", "deployment_manage", "database_admin"})
DEPLOYMENT_OPERATIONS = frozenset({"olist_browser_restart"})
OLIST_REPOSITORY = "Vivaliz-site/amazon-returns-safet"
SHA40 = re.compile(r"^[0-9a-f]{40}$")
VERIFIER = Path(__file__).resolve().parents[1] / "scripts" / "validate-owner-pr-grant.py"
VERIFIER_SUCCESS = "OWNER_PR_GRANT=AUTHORIZED reason=owner_authorization_verified"

HumanAttestor = Callable[[int, str, str], bool]
RuntimePermission = Callable[[str], bool]


def authorize_owner_pr(
    *,
    pr_number: int,
    head_sha: str,
    merge_sha: str,
    capability: str,
    operation: str | None = None,
    target_repository: str | None = None,
    human_attestor: HumanAttestor | None = None,
    runtime_permission: RuntimePermission | None = None,
    runner=None,
) -> tuple[bool, str]:
    """Verify exact merged grant; DENY unless independent protections all pass.

    The attestor is NOT implemented in this staging prototype. A caller that
    cannot prove exclusive human action from a source inaccessible to agents
    must omit it and receive DENIED. A GitHub login alone is never that proof.
    This check must be repeated at the point of each privileged operation.
    """
    if (isinstance(pr_number, bool) or not isinstance(pr_number, int)
            or pr_number <= 0 or not isinstance(head_sha, str)
            or not isinstance(merge_sha, str)
            or not SHA40.fullmatch(head_sha)
            or not SHA40.fullmatch(merge_sha)
            or not isinstance(capability, str)
            or capability not in CAPABILITIES):
        return False, "invalid_pr_or_scope"
    if capability == "deployment_manage" and (not isinstance(operation, str) or operation not in DEPLOYMENT_OPERATIONS):
        return False, "operation_required_for_deployment"
    if capability != "deployment_manage" and operation is not None:
        return False, "unexpected_operation_for_capability"
    if not isinstance(target_repository, str) or not re.fullmatch(
            r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$", target_repository):
        return False, "target_repository_required_for_deployment"
    if capability == "deployment_manage" and target_repository != OLIST_REPOSITORY:
        return False, "target_repository_required_for_deployment"

    if human_attestor is None:
        return False, "human_only_attestation_unavailable"
    if runtime_permission is None:
        return False, "runtime_permission_unavailable"

    try:
        if human_attestor(pr_number, head_sha, merge_sha) is not True:
            return False, "exclusive_human_approval_not_proven"
        if runtime_permission(capability) is not True:
            return False, "runtime_permission_denied"
    except Exception:
        return False, "trusted_authorization_probe_failed"

    # The underlying verifier reads the immutable grant in the actual merge
    # SHA and verifies it remains byte-identical on current main. It also
    # validates owner login, PR/branch, capability, principal and expiration.
    if not VERIFIER.is_file():
        return False, "trusted_verifier_missing"
    if not os.environ.get("SHOPVIVALIZ_SECURITY_OWNER_LOGIN") or not os.environ.get("GITHUB_TOKEN"):
        return False, "trusted_verifier_configuration_missing"

    command = [
        sys.executable, str(VERIFIER),
        "--repository", REPOSITORY,
        "--pr-number", str(pr_number),
        "--head-sha", head_sha,
        "--merge-sha", merge_sha,
        "--resource", RESOURCE,
        "--principal", PRINCIPAL,
        "--capability", capability,
    ]
    if operation is not None:
        command.extend(["--operation", operation])
    if target_repository is not None:
        command.extend(["--target-repository", target_repository])
    try:
        result = (runner or subprocess.run)(
            command, capture_output=True, text=True, timeout=20, check=False,
        )
    except (OSError, subprocess.SubprocessError, TimeoutError, ValueError):
        return False, "owner_pr_verification_unavailable"

    # Do not accept partial strings, mocked environment success flags or a
    # successful exit code with unexpected output. Do not log GitHub API data.
    if result.returncode != 0 or result.stdout.strip() != VERIFIER_SUCCESS:
        return False, "owner_pr_not_verified"
    return True, "exact_owner_pr_grant_verified"


if __name__ == "__main__":
    print("MARINA_OWNER_PR_GATE=DENIED reason=staging_library_only")
    raise SystemExit(1)
