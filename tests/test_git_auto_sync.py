from __future__ import annotations

import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "git-auto-sync.py"
SPEC = importlib.util.spec_from_file_location("git_auto_sync", MODULE_PATH)
sync = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(sync)


def test_runtime_paths_are_allowed_but_source_changes_are_blocked() -> None:
    dirty = [
        "storage/products-cache-ativos.json",
        "storage/orders/SV-1.json",
        ".claude/settings.local.json",
        "index.php",
    ]

    assert sync.unsafe_dirty_paths(dirty) == ["index.php"]


def test_snapshot_and_restore_preserved_order_file(tmp_path: Path, monkeypatch) -> None:
    repo = tmp_path / "repo"
    backup = tmp_path / "backup"
    runtime_file = repo / "storage" / "orders" / "SV-1.json"
    runtime_file.parent.mkdir(parents=True)
    runtime_file.write_text('{"total": 185}\n', encoding="utf-8")
    monkeypatch.setattr(sync, "REPO_DIR", repo)

    preserved = sync.snapshot_preserved_paths(
        ["storage/orders/SV-1.json"], backup
    )
    runtime_file.write_text('{"total": 0}\n', encoding="utf-8")
    sync.restore_preserved_paths(preserved, backup)

    assert preserved == ["storage/orders/SV-1.json"]
    assert runtime_file.read_text(encoding="utf-8") == '{"total": 185}\n'


def test_generated_cache_is_allowed_but_canonical_copy_wins() -> None:
    cache = "storage/products-cache-ativos.json"

    assert sync.unsafe_dirty_paths([cache]) == []
    assert not sync.is_preserved_path(cache)
