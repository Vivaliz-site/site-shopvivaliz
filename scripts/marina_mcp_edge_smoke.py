#!/usr/bin/env python3
"""Read-only smoke test of the public Marina MCP route with the Access service token.

Runs initialize and tools/list only: it never calls a tool, so it cannot run the
admin shell or the browser. It catches the failures that broke the agent client
silently: edge blocks and Access refusals (exit 3), a credential that stopped
working or went missing (exit 2), and a tool set that drifted from the expected
one (exit 5). Output carries names and counts, never credential values.

Exit codes: 0 ok, 2 credentials missing or unsafe, 3 edge or Access failure,
5 expected tools missing from the served MCP.
"""
from __future__ import annotations

import importlib.util
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
EXPECTED_TOOLS = {
    "marina_admin_shell",
    "marina_browser_navigate",
    "marina_gui_session",
    "marina_host_health",
}


def load_client(path: Path = HERE / "marina_mcp_agent_client.py"):
    spec = importlib.util.spec_from_file_location("marina_mcp_agent_client", path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def smoke(client, endpoint: str | None = None) -> tuple[int, dict]:
    try:
        client_id = client.load_credential(client.ID_NAME)
        client_secret = client.load_credential(client.SECRET_NAME)
    except client.Refused as refusal:
        return 2, {"status": "CREDENTIALS_NOT_READY", "detail": str(refusal)}
    url = endpoint or client.DEFAULT_ENDPOINT
    try:
        session = client.Client(url, client_id, client_secret)
        session.initialize()
        reply = session.rpc("tools/list", {})
    except client.Refused as refusal:
        return 2, {"status": "ENDPOINT_REFUSED", "detail": str(refusal)}
    except ConnectionError as failure:
        return 3, {"status": "EDGE_OR_ACCESS_FAILURE", "detail": str(failure)}
    tools = {tool["name"] for tool in reply["result"]["tools"]}
    missing = sorted(EXPECTED_TOOLS - tools)
    if missing:
        return 5, {"status": "TOOLSET_DRIFT", "missing": missing, "served": len(tools)}
    return 0, {"status": "OK", "expected_tools_present": len(EXPECTED_TOOLS), "served": len(tools)}


def main(argv=None) -> int:
    endpoint = (argv or sys.argv[1:] or [None])[0]
    code, report = smoke(load_client(), endpoint)
    print(json.dumps(report, indent=2))
    return code


if __name__ == "__main__":
    sys.exit(main())
