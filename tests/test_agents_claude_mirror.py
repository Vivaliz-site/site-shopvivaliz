"""AGENTS.md and CLAUDE.md are byte-identical mirrors (owner request, 2026-11-05)."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
AGENTS = ROOT / "AGENTS.md"
CLAUDE = ROOT / "CLAUDE.md"
SYNC = ROOT / "scripts" / "sync-agent-instructions.py"


def test_claude_md_is_a_byte_identical_mirror_of_agents_md():
    assert AGENTS.read_bytes() == CLAUDE.read_bytes(), "run: python3 scripts/sync-agent-instructions.py"


def test_sync_check_reports_in_sync():
    result = subprocess.run([sys.executable, str(SYNC), "--check"], capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr


def test_the_owner_rule_and_the_claude_imports_are_in_the_mirror():
    text = AGENTS.read_text(encoding="utf-8")
    assert "OWNER_NO_UNAPPROVED_RESTRICTIONS_20261105" in text
    assert "@docs/knowledge/claude-vm-bootstrap.md" in text
    assert "@docs/AGENTS.md" in text


if __name__ == "__main__":
    test_claude_md_is_a_byte_identical_mirror_of_agents_md()
    test_sync_check_reports_in_sync()
    test_the_owner_rule_and_the_claude_imports_are_in_the_mirror()
    print("AGENTS_CLAUDE_MIRROR=PASS")
