"""Regression: the served MCP must not advertise the retired read-only server name."""
from pathlib import Path

SERVER = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "server.py"


def test_server_name_does_not_claim_readonly():
    source = SERVER.read_text(encoding="utf-8")
    assert '"shopvivaliz-marina-direct-readonly"' not in source
    assert source.count('"shopvivaliz-marina-direct"') >= 2


if __name__ == "__main__":
    test_server_name_does_not_claim_readonly()
    print("PASS test_server_name_does_not_claim_readonly")
