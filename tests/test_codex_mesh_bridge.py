from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "scripts" / "codex-mesh-bridge.py"


def test_stdio_protocol_is_utf8_and_lists_tools(tmp_path: Path) -> None:
    requests = [
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "initialize",
            "params": {
                "protocolVersion": "2025-11-25",
                "capabilities": {},
                "clientInfo": {"name": "pytest", "version": "1"},
            },
        },
        {
            "jsonrpc": "2.0",
            "method": "notifications/initialized",
            "params": {},
        },
        {"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}},
    ]
    wire_input = "".join(json.dumps(item) + "\n" for item in requests).encode("utf-8")
    env = os.environ.copy()
    env["CODEX_BRIDGE_DATA_DIR"] = str(tmp_path / "bridge-data")

    completed = subprocess.run(
        [sys.executable, str(BRIDGE)],
        input=wire_input,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        cwd=ROOT,
        timeout=10,
        check=True,
    )

    # Strict decoding is intentional: MCP stdio requires UTF-8 on every OS.
    responses = [json.loads(line) for line in completed.stdout.decode("utf-8").splitlines()]
    tools_response = next(item for item in responses if item.get("id") == 2)
    tools = tools_response["result"]["tools"]

    assert {tool["name"] for tool in tools} == {"post_message", "read_messages", "bridge_status"}
    assert any("Lê mensagens" in tool["description"] for tool in tools)
