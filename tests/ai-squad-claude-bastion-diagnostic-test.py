#!/usr/bin/env python3
from pathlib import Path

workflow = (Path(__file__).resolve().parents[1] / ".github/workflows/oci-bastion-private-access-bootstrap.yml").read_text(encoding="utf-8")

required = (
    "action=ai-squad-claude-diagnostic",
    "Diagnose AI Squad Claude bridge through Remote Control MCP",
    "AI_SQUAD_CLAUDE_SERVICE_ACTIVE=",
    "AI_SQUAD_CLAUDE_HEALTH_OK=",
    "AI_SQUAD_CLAUDE_AUTH_LOGGED_IN=",
    "AI_SQUAD_CLAUDE_PROBE_FAILURE_CLASS=",
    "process_matches=\"$(pgrep -u ubuntu -f '/home/ubuntu/.local/bin/claude' 2>/dev/null || true)\"",
    "'host': 'shopvivaliz-free-a1'",
)
for marker in required:
    assert marker in workflow, marker

for forbidden in (
    "cat /home/ubuntu/.claude/.credentials.json",
    "cat ~/.claude/.credentials.json",
    "print(stderr",
    "print(stdout",
):
    assert forbidden not in workflow, forbidden

print("AI_SQUAD_CLAUDE_BASTION_DIAGNOSTIC_CONTRACT=PASS")
