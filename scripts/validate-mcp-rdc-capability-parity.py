#!/usr/bin/env python3
"""Validate scope and truthfulness of the RDC/MCP parity discovery matrix.

Metadata is not a host authorization, runtime switch, or evidence of E2E.
Never make a connector executable from the contents of this document.
"""
from __future__ import annotations

import json
from pathlib import Path

SCHEMA = "SHOPVIVALIZ_RDC_MCP_CAPABILITY_PARITY_V1"
RDC_METHODS = frozenset("""
list_devices who_am_i get_tool_result list_remote_tool_calls shutdown
get_config set_config_value read_file read_multiple_files write_file write_pdf
create_directory list_directory move_file start_search get_more_search_results
stop_search list_searches get_file_info edit_block start_process
read_process_output interact_with_process force_terminate list_sessions
list_processes kill_process get_usage_stats get_recent_tool_calls
""".split())
STATUS = frozenset({
    "DIRECT_CANDIDATE", "PARTIAL_ANALOGUE", "GENERIC_SURROGATE",
    "NOT_IMPLEMENTED",
})
VALID_HOSTS = frozenset({
    "always-free-arm-1787907847-26", "marina-a1-4c24g",
    "Fred-Win", "KOCEPSV",
})
RETIRED = "shopvivaliz-free-a1"
READ_ONLY_STAGE = frozenset({"marina_host_health", "marina_service_status"})
INDEPENDENT_STAGED = frozenset({
    "marina_host_health", "marina_service_status", "marina_processes_list",
    "marina_system_usage", "marina_kernel_telemetry", "marina_kernel_telemetry_batch",
})


def validate(document: object) -> tuple[bool, str]:
    if not isinstance(document, dict):
        return False, "invalid_document"
    if document.get("schema") != SCHEMA or document.get("certification") != "NOT_CERTIFIED":
        return False, "no_runtime_certification_without_evidence"
    if document.get("captured_date") != "2026-11-01":
        return False, "invalid_capture"
    if set(document.get("target_hosts", [])) != VALID_HOSTS:
        return False, "unexpected_host_inventory"
    if document.get("retired_host") != RETIRED or RETIRED in document.get("target_hosts", []):
        return False, "retired_host_reintroduced"
    state = document.get("service_state")
    if not isinstance(state, dict) or not isinstance(state.get("central"), dict):
        return False, "central_state_missing"
    central = state["central"]
    if set(central.get("hosts", [])) != VALID_HOSTS:
        return False, "active_host_truth_violation"
    marina_central = central.get("marina", {})
    if not isinstance(marina_central, dict) or (
        marina_central.get("host") != "marina-a1-4c24g"
        or marina_central.get("route") != "private_ssh_read_only"
        or marina_central.get("code_state") != "INSTALLED_RESTRICTED"
        or marina_central.get("runtime_state") != "READ_ONLY_ROUTE_FLAG_ENABLED"
        or marina_central.get("authorization_state") != "OWNER_GRANT_APPROVED_ATTESTED"
        or marina_central.get("chatgpt_tool_catalog") != "STALE_REFRESH_REQUIRED"
        or marina_central.get("e2e") != "NOT_TESTED"
        or set(marina_central.get("server_tools", [])) != READ_ONLY_STAGE
    ):
        return False, "central_marina_route_not_certified"
    independent = state.get("marina", {})
    if (not isinstance(independent, dict)
            or independent.get("state") != "OFFLINE_NOT_CONNECTED"
            or independent.get("local_staging_status") != "LOCAL_E2E_PASS_NOT_INSTALLED_NOT_CONNECTED"
            or set(independent.get("local_staging_tools", [])) != INDEPENDENT_STAGED):
        return False, "independent_marina_unverified"
    requirements = document.get("requirements", {})
    if not isinstance(requirements, dict):
        return False, "invalid_requirements"
    if requirements.get("independent_marina") is not True or requirements.get("rdc_dependency_for_normal_operation") is not False:
        return False, "independent_route_contract_violated"
    for key in ("no_cross_host_relay", "exact_owner_security_pr_required_for_new_grants",
                "host_rbac_and_leases_required", "no_generic_unrestricted_shell",
                "ui_e2e_for_gui_capabilities", "deny_on_unproven_capability"):
        if requirements.get(key) is not True:
            return False, "security_requirements_violated"
    rows = document.get("capabilities")
    if not isinstance(rows, list) or len(rows) != len(RDC_METHODS):
        return False, "incomplete_rdc_capability_inventory"
    seen = set()
    for row in rows:
        if not isinstance(row, dict) or set(row) != {
            "rdc_method", "family", "central_tools", "central_coverage",
            "marina_tools", "marina_coverage", "certification", "last_verified_e2e",
        }:
            return False, "invalid_capability_row"
        method = row["rdc_method"]
        if not isinstance(method, str) or method not in RDC_METHODS or method in seen:
            return False, "unknown_or_duplicate_rdc_method"
        seen.add(method)
        if row["central_coverage"] not in STATUS:
            return False, "invalid_coverage_claim"
        if not isinstance(row["central_tools"], list) or any(not isinstance(v, str) for v in row["central_tools"]):
            return False, "invalid_central_tool_list"
        if row["marina_tools"] != [] or row["marina_coverage"] != "OFFLINE_NOT_CONNECTED":
            return False, "invented_marina_coverage"
        if row["certification"] != "NOT_CERTIFIED" or row["last_verified_e2e"] is not None:
            return False, "invented_runtime_evidence"
    if seen != RDC_METHODS:
        return False, "missing_rdc_methods"
    return True, "discovery_only"


if __name__ == "__main__":
    path = Path(__file__).resolve().parents[1] / "docs/ops/mcp-rdc-capability-parity-2026-11-01.json"
    try:
        ok, reason = validate(json.loads(path.read_text(encoding="utf-8")))
    except (ValueError, OSError, TypeError):
        ok, reason = False, "matrix_unavailable_or_invalid"
    print(f"RDC_MCP_PARITY_INVENTORY={'PASS' if ok else 'FAIL'} reason={reason}")
    raise SystemExit(0 if ok else 1)
