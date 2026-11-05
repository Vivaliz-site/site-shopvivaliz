"""Tests for the live-vs-attested MCP tool inventory diff (no network, no secrets)."""
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import mcp_tool_inventory_diff as inv  # noqa: E402

PARITY = json.loads((ROOT / "docs/ops/mcp-rdc-capability-parity-2026-11-01.json").read_text(encoding="utf-8"))
SNAPSHOT = json.loads((ROOT / "docs/ops/mcp-live-tool-inventory-2026-11-05.json").read_text(encoding="utf-8"))


def test_reports_live_tools_missing_from_the_attested_matrix():
    live = sorted(PARITY["service_state"]["marina"]["local_staging_tools"] + ["marina_admin_shell", "marina_gui_session"])
    report = inv.diff(PARITY, live)
    assert report["live_not_documented"] == ["marina_admin_shell", "marina_gui_session"]
    assert report["documented_not_live"] == []


def test_reports_documented_tools_that_are_not_live():
    report = inv.diff(PARITY, ["marina_host_health"])
    assert "marina_processes_list" in report["documented_not_live"]


def test_flags_offline_claim_when_live_list_answers():
    report = inv.diff(PARITY, ["marina_host_health"])
    assert report["state_conflicts"], "stale OFFLINE_NOT_CONNECTED claim must be reported"


def test_never_certifies_and_never_mutates_the_document():
    before = json.dumps(PARITY, sort_keys=True)
    report = inv.diff(PARITY, ["marina_host_health"])
    assert report["certification"] == "NOT_CERTIFIED"
    assert json.dumps(PARITY, sort_keys=True) == before


def test_parse_live_accepts_the_client_shapes_and_rejects_garbage():
    assert inv.parse_live({"tools": ["b", "a"]}) == ["a", "b"]
    assert inv.parse_live([{"name": "x"}, {"name": "w"}]) == ["w", "x"]
    for bad in ({"tools": "no"}, "text", None):
        try:
            inv.parse_live(bad)
        except ValueError:
            continue
        raise AssertionError(bad)


def test_committed_snapshot_is_consistent_and_not_certified():
    assert SNAPSHOT["certification"] == "NOT_CERTIFIED"
    assert SNAPSHOT["report"]["live_count"] == len(SNAPSHOT["live_tools"]) == 9
    assert SNAPSHOT["report"]["live_not_documented"] == sorted(
        set(SNAPSHOT["live_tools"]) - inv.documented_marina_tools(PARITY))
    text = json.dumps(SNAPSHOT).lower()
    for forbidden in ("client_secret", "bearer ", "cf-access-client"):
        assert forbidden not in text


if __name__ == "__main__":
    for name, fn in sorted(globals().items()):
        if name.startswith("test_"):
            fn()
    print("MCP_TOOL_INVENTORY_DIFF=PASS")
