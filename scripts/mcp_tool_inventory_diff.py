#!/usr/bin/env python3
"""Compare the live Marina MCP tool list with the attested parity matrix.

Read-only and non-certifying. It never edits the parity document and never marks
anything certified: the matrix is an attested governance artifact owned by the
certifier. This tool only reports where the document is stale against a live list.

Usage:
  mcp_tool_inventory_diff.py --live-json tools.json [--parity docs/ops/...json]
  mcp_tool_inventory_diff.py --from-client      # uses scripts/marina_mcp_agent_client.py tools
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_PARITY = ROOT / "docs/ops/mcp-rdc-capability-parity-2026-11-01.json"
CLIENT = ROOT / "scripts/marina_mcp_agent_client.py"


def documented_marina_tools(parity: dict) -> set[str]:
    names: set[str] = set()
    marina = parity.get("service_state", {}).get("marina", {})
    names.update(marina.get("local_staging_tools", []) or [])
    for capability in parity.get("capabilities", []):
        names.update(capability.get("marina_tools", []) or [])
    return {n for n in names if isinstance(n, str) and n.startswith("marina_")}


def diff(parity: dict, live_tools: list[str]) -> dict:
    live = set(live_tools)
    documented = documented_marina_tools(parity)
    state = parity.get("service_state", {}).get("marina", {}).get("state")
    conflicts = []
    if live and state == "OFFLINE_NOT_CONNECTED":
        conflicts.append("document_says_marina_independent_offline_but_live_tools_list_answered")
    return {
        "certification": "NOT_CERTIFIED",
        "live_count": len(live),
        "live_not_documented": sorted(live - documented),
        "documented_not_live": sorted(documented - live),
        "state_conflicts": conflicts,
        "note": "Report only. The parity matrix is attested; the certifier decides any update.",
    }


def read_live_from_client() -> list[str]:
    result = subprocess.run([sys.executable, str(CLIENT), "tools"], capture_output=True,
                            text=True, timeout=120, check=False)
    if result.returncode != 0:
        raise SystemExit(f"client_failed_exit_{result.returncode}")
    return parse_live(json.loads(result.stdout))


def parse_live(data: object) -> list[str]:
    items = data.get("tools") if isinstance(data, dict) else data
    if not isinstance(items, list):
        raise ValueError("live_tools_invalid")
    return sorted(i["name"] if isinstance(i, dict) else str(i) for i in items)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--parity", default=str(DEFAULT_PARITY))
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--live-json")
    source.add_argument("--from-client", action="store_true")
    args = parser.parse_args()
    parity = json.loads(Path(args.parity).read_text(encoding="utf-8"))
    live = read_live_from_client() if args.from_client else parse_live(
        json.loads(Path(args.live_json).read_text(encoding="utf-8")))
    print(json.dumps(diff(parity, live), indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
