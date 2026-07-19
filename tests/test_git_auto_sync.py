from __future__ import annotations

import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "git-auto-sync.py"
SPEC = importlib.util.spec_from_file_location("git_auto_sync", MODULE_PATH)
sync = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(sync)


def test_any_dirty_path_blocks_safe_sync() -> None:
    dirty = [
        "storage/products-cache-ativos.json",
        "storage/orders/SV-1.json",
        ".claude/settings.local.json",
        "index.php",
    ]

    assert sync.unsafe_dirty_paths(dirty) == [
        "storage/products-cache-ativos.json",
        "storage/orders/SV-1.json",
        ".claude/settings.local.json",
        "index.php",
    ]


def test_generated_cache_blocks_safe_sync() -> None:
    cache = "storage/products-cache-ativos.json"

    assert sync.unsafe_dirty_paths([cache]) == [cache]
    assert not sync.is_preserved_path(cache)


def test_production_services_follow_main_and_run_detail_sync() -> None:
    root = MODULE_PATH.parent
    git_sync = (root / "deploy/systemd/shopvivaliz-sync.service").read_text(encoding="utf-8")
    product_sync = (root / "deploy/systemd/shopvivaliz-sync-products.service").read_text(
        encoding="utf-8"
    )

    assert "SHOPVIVALIZ_SYNC_BRANCH=main" in git_sync
    assert "daemon-sync-products.py --interval 300 --workers 4" in product_sync
    assert "User=ubuntu" in product_sync
    assert "Group=www-data" in product_sync
    token_sync = (root / "deploy/systemd/shopvivaliz-token-renewer.service").read_text(
        encoding="utf-8"
    )
    assert "daemon-token-renewer.py --interval 7200 --retry-interval 900" in token_sync
