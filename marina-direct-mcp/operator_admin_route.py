#!/usr/bin/env python3
"""Fail-closed local Marina maintenance gate; intentionally NOT network-exposed.

This is an internal adapter for a future officially provisioned privileged
operator. It supplies no host permissions, secrets, tunnel, or caller identity.
All injected authority callbacks MUST be implemented in protected host code:
never instantiate this class with RPC/client-controlled callback values.
"""
from __future__ import annotations

import re
from collections.abc import Mapping
from typing import Protocol

MARINA_HOST = "marina-a1-4c24g"
OLIST_BROWSER_UNIT = "amazon-returns-olist-erp-browser.service"
OPERATION = "olist_browser_restart"
CAPABILITY = "deployment_manage"
_REQUEST_KEYS = frozenset({
    "operation", "pr_number", "head_sha", "merge_sha",
    "idempotency_key", "fencing_token",
})
_SHA = re.compile(r"^[0-9a-f]{40}$")
_KEY = re.compile(r"^[a-z0-9][a-z0-9-]{7,127}$")
_MAX_FENCE = 2**63 - 1


class ProtectedOperator(Protocol):
    """Root-owned host implementation; never sourced from a remote request."""
    def hostname(self) -> str: ...
    def effective_uid(self) -> int: ...
    def olist_config_is_ready(self) -> bool: ...
    def owner_pr_grant_is_valid(self, pr: int, head: str, merge: str, capability: str) -> bool: ...
    def runtime_permission_is_valid(self) -> bool: ...
    def audit_ready(self, operation: str, request_id: str) -> bool: ...
    def lease_reserve_once(self, operation: str, request_id: str, fencing: int) -> bool: ...
    def restart_service(self, unit: str) -> bool: ...


def execute_admin_operation(request: object, operator: ProtectedOperator | None) -> tuple[bool, str]:
    """Admit one fixed restart only when every independent host gate passes.

    No generic command, service name, destination, arbitrary file path,
    credential, ACL, UID, or callback is accepted from the request.
    """
    if not isinstance(request, Mapping) or set(request) != _REQUEST_KEYS:
        return False, "unsupported_request_shape"
    if request.get("operation") != OPERATION:
        return False, "unsupported_operation"

    pr = request.get("pr_number")
    head = request.get("head_sha")
    merge = request.get("merge_sha")
    rid = request.get("idempotency_key")
    fencing = request.get("fencing_token")

    if (type(pr) is not int or pr <= 0 or
            not isinstance(head, str) or _SHA.fullmatch(head) is None or
            not isinstance(merge, str) or _SHA.fullmatch(merge) is None or
            not isinstance(rid, str) or _KEY.fullmatch(rid) is None or
            type(fencing) is not int or not 0 < fencing <= _MAX_FENCE):
        return False, "invalid_pr_or_lease_binding"

    if operator is None:
        return False, "host_operator_unavailable"
    try:
        if operator.hostname() != MARINA_HOST:
            return False, "wrong_host"
        if operator.effective_uid() != 0:
            return False, "privileged_host_operator_unavailable"
        if operator.olist_config_is_ready() is not True:
            return False, "protected_olist_runtime_not_ready"
        if operator.owner_pr_grant_is_valid(pr, head, merge, CAPABILITY) is not True:
            return False, "human_only_owner_pr_grant_not_verified"
        if operator.runtime_permission_is_valid() is not True:
            return False, "effective_runtime_permission_denied"
        if operator.audit_ready(OPERATION, rid) is not True:
            return False, "protected_audit_unavailable"
        # This method MUST atomically reject stale fencing and replayed request
        # IDs in a protected host-owned durable store before any side effect.
        if operator.lease_reserve_once(OPERATION, rid, fencing) is not True:
            return False, "maintenance_lease_or_replay_denied"
        # The only mutating operation in this adapter; no user commands accepted.
        if operator.restart_service(OLIST_BROWSER_UNIT) is not True:
            return False, "service_restart_unverified"
    except Exception:
        # No exception details: secrets and provider responses never enter logs.
        return False, "trusted_host_capability_failed"
    return True, "restart_requested"


if __name__ == "__main__":
    # Direct execution cannot supply a trusted operator and MUST fail closed.
    print("MARINA_ADMIN_ROUTE=DENIED reason=no_trusted_operator")
    raise SystemExit(1)
