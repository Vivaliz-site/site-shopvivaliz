#!/usr/bin/env python3
from pathlib import Path

workflow = (Path(__file__).resolve().parents[1] / ".github/workflows/oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")

required = (
    "action=ai-squad-claude-diagnostic",
    "Diagnose AI Squad Claude bridge through Remote Control MCP",
    "AI_SQUAD_CLAUDE_SERVICE_ACTIVE=",
    "AI_SQUAD_CLAUDE_HEALTH_OK=",
    "AI_SQUAD_CLAUDE_AUTH_LOGGED_IN=",
    "AI_SQUAD_CLAUDE_VERSION=",
    "AI_SQUAD_CLAUDE_SERVICE_ENV_BASE_URL_SET=",
    "AI_SQUAD_CLAUDE_SERVICE_ENV_API_KEY_SET=",
    "AI_SQUAD_CLAUDE_SERVICE_ENV_AUTH_TOKEN_SET=",
    "AI_SQUAD_CLAUDE_API_REACHABLE=",
    "AI_SQUAD_CLAUDE_API_DNS_OK=",
    "AI_SQUAD_CLAUDE_PROBE_FAILURE_CLASS=",
    "AI_SQUAD_CLAUDE_PROBE_TRANSPORT_DETAIL=",
    "connection_refused",
    "connection_reset",
    "workspace_trust",
    "--debug-file",
    "pgrep -c -u ubuntu",
    "'host': 'shopvivaliz-free-a1'",
)
for marker in required:
    assert marker in workflow, marker

for forbidden in (
    "cat /home/ubuntu/.claude/.credentials.json",
    "cat ~/.claude/.credentials.json",
    "print(stderr",
    "print(stdout",
    "AI_SQUAD_CLAUDE_SERVICE_ENV_BASE_URL_VALUE=",
    "AI_SQUAD_CLAUDE_DEBUG_RAW=",
):
    assert forbidden not in workflow, forbidden

print("AI_SQUAD_CLAUDE_BASTION_DIAGNOSTIC_CONTRACT=PASS")
