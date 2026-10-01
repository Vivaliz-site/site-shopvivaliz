#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "vm-health-resource-check.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml"

script = SCRIPT.read_text(encoding="utf-8")
workflow = WORKFLOW.read_text(encoding="utf-8")

for marker in (
    "VM_HEALTH_MONITOR_BEGIN",
    "DISK_USE_PCT=",
    "INODE_USE_PCT=",
    "MEM_AVAILABLE_PCT=",
    "SWAP_USE_PCT=",
    "LOAD5_PER_CPU=",
    "FAILED_UNIT_COUNT=",
    "VM_HEALTH=",
    "VM_HEALTH_SAFE_REPAIR_BEGIN",
    "shopvivaliz-remote-control-mcp.service",
    "shopvivaliz-chatgpt-continuity.service",
    "apache2.service",
    "shopvivaliz-queue-worker.service",
):
    assert marker in script, marker

for forbidden in (
    "rm -rf",
    "docker system prune",
    "journalctl --vacuum",
    "apt clean",
    "swapoff",
    "reboot",
    "shutdown",
):
    assert forbidden not in script, forbidden

assert "schedule:" in workflow
assert "17 */6 * * *" in workflow
assert "issues: write" in workflow
assert "github.event_name == 'schedule'" in workflow
assert "Six-hour VM health monitor and safe repair" in workflow
assert "scripts/vm-health-resource-check.sh" in workflow
assert "[vm-health-alert]" in workflow
assert "runtime-status-policy.sh" in workflow

print("VM_HEALTH_6H_CONTRACT=PASS")
