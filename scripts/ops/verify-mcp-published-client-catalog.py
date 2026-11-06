#!/usr/bin/env python3
"""Compare the *published* client MCP admin host schemas with the server catalog.

This is a read-only offline diagnostic, not an access grant or a catalog publisher.
It deliberately requires BOTH tool catalogs. A healthy server/hosts_list cannot
prove that a ChatGPT workspace app has refreshed its frozen input schemas.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any

RETIRED_HOST = "shopvivaliz-free-a1"
MARINA_HOST = "marina-a1-4c24g"
ACTIVE_HOSTS = frozenset({
    "always-free-arm-1787907847-26", "Fred-Win", "KOCEPSV", MARINA_HOST
})
ADMIN_TOOLS = ("admin_command_run", "task_submit", "service_action")
OTHER_HOST_TOOLS = (
    "host_health", "service_status", "processes_list", "file_read",
    "file_list", "logs_tail",
)
MAX_BYTES = 2 * 1024 * 1024


class CatalogError(ValueError):
    pass


def tool_map(payload: Any) -> dict[str, dict[str, Any]]:
    """Accept an official tools/list result or its unwrapped tools array."""
    if isinstance(payload, dict) and isinstance(payload.get("result"), dict):
        payload = payload["result"]
    if isinstance(payload, dict):
        payload = payload.get("tools")
    if not isinstance(payload, list) or not 0 < len(payload) < 500:
        raise CatalogError("tools_list_missing_or_invalid")
    result = {}
    for tool in payload:
        if not isinstance(tool, dict):
            raise CatalogError("invalid_tool_record")
        name = tool.get("name")
        if not isinstance(name, str) or not name or name in result:
            raise CatalogError("missing_or_duplicate_tool_name")
        result[name] = tool
    return result


def host_enum(tool: dict[str, Any]) -> frozenset[str]:
    schema = tool.get("inputSchema")
    if not isinstance(schema, dict):
        raise CatalogError("missing_input_schema")
    props = schema.get("properties")
    if not isinstance(props, dict) or not isinstance(props.get("host"), dict):
        raise CatalogError("missing_host_parameter")
    values = props["host"].get("enum")
    if (not isinstance(values, list) or not values
            or any(not isinstance(v, str) or not v for v in values)
            or len(set(values)) != len(values)):
        raise CatalogError("invalid_host_enum")
    return frozenset(values)


def compare(server_payload: Any, client_payload: Any) -> list[str]:
    """Fail closed on a stale client; do not use inventory as authorization."""
    problems: list[str] = []
    try:
        server, client = tool_map(server_payload), tool_map(client_payload)
    except CatalogError as exc:
        return [str(exc)]
    for name in ADMIN_TOOLS:
        if name not in server or name not in client:
            problems.append("required_tool_missing:" + name)
            continue
        try:
            live, published = host_enum(server[name]), host_enum(client[name])
        except CatalogError as exc:
            problems.append(name + ":" + str(exc))
            continue
        if RETIRED_HOST in live:
            problems.append("server_retired_host:" + name)
        if RETIRED_HOST in published:
            problems.append("client_retired_host:" + name)
        if live != ACTIVE_HOSTS:
            problems.append("server_active_host_contract_mismatch:" + name)
        if published != live:
            problems.append("client_schema_stale:" + name)
    for name in OTHER_HOST_TOOLS:
        if name not in server:
            continue
        if name not in client:
            problems.append("client_tool_missing:" + name)
            continue
        try:
            live, published = host_enum(server[name]), host_enum(client[name])
        except CatalogError as exc:
            problems.append(name + ":" + str(exc))
            continue
        if RETIRED_HOST in published or RETIRED_HOST in live:
            problems.append("retired_host:" + name)
        if published != live:
            problems.append("client_schema_stale:" + name)
    return sorted(set(problems))


def read_catalog(path: Path) -> Any:
    if not path.is_file() or path.stat().st_size > MAX_BYTES:
        raise CatalogError("catalog_file_missing_or_too_large")
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        raise CatalogError("invalid_catalog_json") from exc


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--server", type=Path, required=True,
                        help="authenticated server tools/list JSON (no secrets)")
    parser.add_argument("--client", type=Path, required=True,
                        help="official exported published ChatGPT app tool catalog")
    parser.add_argument("--client-name", choices=("shopvivaliz-remote-control",
                                                   "orquestrador"), required=True)
    args = parser.parse_args()
    try:
        problems = compare(read_catalog(args.server), read_catalog(args.client))
    except (OSError, CatalogError) as exc:
        problems = [str(exc)]
    state = "PASS" if not problems else "FAIL"
    print(f"MCP_PUBLISHED_CLIENT_CATALOG={state} client={args.client_name}")
    for problem in problems:
        print("MCP_CATALOG_FINDING=" + problem)
    if problems:
        print("MCP_CATALOG_ACTION=official_workspace_app_action_refresh_required")
    return 0 if not problems else 2


if __name__ == "__main__":
    raise SystemExit(main())
