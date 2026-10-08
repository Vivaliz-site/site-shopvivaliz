from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github/workflows/shopvivaliz-remote-access.yml"
SCRIPT = ROOT / "scripts/olist-nf-repair.py"

def test_remote_action_is_allowlisted_and_site_only():
    text = WF.read_text(encoding="utf-8")
    assert "olist_nf_repair" in text
    assert 'action == "olist_nf_repair"' in text
    assert "Olist NF repair is restricted to the site VM" in text

def test_remote_action_invokes_bounded_repair_script():
    text = WF.read_text(encoding="utf-8")
    assert "scripts/olist-nf-repair.py" in text
    assert "--note-ids 386925785,386927448" in text
    assert "--order-ids 386925781,386927447" in text

def test_repair_script_exists_and_is_bounded_to_known_cases():
    assert SCRIPT.exists()
    text = SCRIPT.read_text(encoding="utf-8")
    for value in ("386925785","386927448","386925781","386927447","261004NT8UK1GS","261004NU12WQ1G","9403.20.90","7326.90.90"):
        assert value in text
    assert "OLIST_NF_REPAIR_TERMINAL" in text
