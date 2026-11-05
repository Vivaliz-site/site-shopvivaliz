"""AGENTS.md, CLAUDE.md, GEMINI.md and docs/AGENTS.md are identical mirrors (owner request, 2026-11-05).

An inclusion or change in any of them must be reflected in the others; the sync script does it.
"""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
FILES = ("AGENTS.md", "CLAUDE.md", "GEMINI.md", "docs/AGENTS.md")
SYNC = ROOT / "scripts" / "sync-agent-instructions.py"


def _sync(root, *args):
    env = dict(os.environ, SHOPVIVALIZ_AGENT_INSTRUCTIONS_ROOT=str(root))
    return subprocess.run([sys.executable, str(SYNC), *args], capture_output=True, text=True, env=env)


def _git(root, *args):
    subprocess.run(["git", "-C", str(root), "-c", "user.name=t", "-c", "user.email=t@t", *args],
                   check=True, capture_output=True)


def _temp_repo(text="line one\nline two\nline three\nline four\nline five\n"):
    root = Path(tempfile.mkdtemp())
    (root / "docs").mkdir()
    _git(root, "init", "-q")
    for name in FILES:
        (root / name).write_text(text, encoding="utf-8")
    _git(root, "add", "-A")
    _git(root, "commit", "-qm", "base")
    return root


def test_the_four_files_are_byte_identical():
    contents = {name: (ROOT / name).read_bytes() for name in FILES}
    assert len(set(contents.values())) == 1, "run: python3 scripts/sync-agent-instructions.py"


def test_sync_check_reports_in_sync():
    result = subprocess.run([sys.executable, str(SYNC), "--check"], capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr


def test_the_owner_rule_and_imports_are_in_the_mirror():
    text = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
    assert "OWNER_NO_UNAPPROVED_RESTRICTIONS_20261105" in text
    assert "@docs/knowledge/claude-vm-bootstrap.md" in text


def test_an_edit_in_any_file_is_reflected_in_the_other_three():
    for edited in FILES:
        root = _temp_repo()
        path = root / edited
        path.write_text(path.read_text(encoding="utf-8") + f"added in {edited}\n", encoding="utf-8")
        assert _sync(root, "--check").returncode == 1
        assert _sync(root).returncode == 0, edited
        for name in FILES:
            assert f"added in {edited}" in (root / name).read_text(encoding="utf-8"), (edited, name)
        assert _sync(root, "--check").returncode == 0


def test_edits_to_different_places_of_different_files_are_both_kept():
    root = _temp_repo()
    (root / "CLAUDE.md").write_text("changed top\nline two\nline three\nline four\nline five\n", encoding="utf-8")
    (root / "GEMINI.md").write_text("line one\nline two\nline three\nline four\nchanged bottom\n", encoding="utf-8")
    assert _sync(root).returncode == 0
    merged = (root / "AGENTS.md").read_text(encoding="utf-8")
    assert merged.startswith("changed top") and merged.rstrip().endswith("changed bottom")
    assert len({(root / n).read_bytes() for n in FILES}) == 1


def test_conflicting_edits_fail_without_changing_anything():
    root = _temp_repo()
    (root / "CLAUDE.md").write_text("line one\nA\nline three\nline four\nline five\n", encoding="utf-8")
    (root / "GEMINI.md").write_text("line one\nB\nline three\nline four\nline five\n", encoding="utf-8")
    before = {n: (root / n).read_bytes() for n in FILES}
    result = _sync(root)
    assert result.returncode == 1 and "CONFLICT" in result.stderr
    assert {n: (root / n).read_bytes() for n in FILES} == before


def test_from_copies_one_file_to_the_others():
    root = _temp_repo()
    (root / "docs/AGENTS.md").write_text("only this\n", encoding="utf-8")
    assert _sync(root, "--from", "docs/AGENTS.md").returncode == 0
    assert all((root / n).read_text(encoding="utf-8") == "only this\n" for n in FILES)


if __name__ == "__main__":
    for name, fn in sorted(globals().items()):
        if name.startswith("test_"):
            fn()
    print("AGENT_INSTRUCTIONS_MIRROR_TESTS=PASS")
