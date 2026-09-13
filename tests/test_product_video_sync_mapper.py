from pathlib import Path

from scripts.product_video_sync.config import Settings
from scripts.product_video_sync.hosted_videos import build_video_inventory
from scripts.product_video_sync.mapper import map_product_video


def settings(tmp_path):
    return Settings(
        tiny_api_base_url="https://api.tiny.com.br/public-api/v3",
        video_base_url="https://shopvivaliz.com.br/uploads/videos-produtos/",
        video_dir=Path(tmp_path),
        token_file=Path(tmp_path) / "tokens.json",
        timeout_seconds=20,
        max_retries=2,
        page_size=100,
        allow_playwright_fallback=False,
    )


def touch(tmp_path, *names):
    for name in names:
        (tmp_path / name).write_bytes(b"video")
    return build_video_inventory(tmp_path)


def test_inventory_ignores_non_video_files(tmp_path):
    inventory = touch(tmp_path, "ABC.mp4", "ABC.jpg", "movie.webm", "notes.txt")

    assert sorted(inventory.files) == ["ABC.mp4", "movie.webm"]


def test_explicit_tiny_attachment_beats_sku_match(tmp_path):
    inventory = touch(tmp_path, "SKU-1.mp4", "preferred.mp4")
    product = {"id": 123, "sku": "SKU-1"}
    detail = {"anexos": [{"url": "https://shopvivaliz.com.br/uploads/videos-produtos/preferred.mp4"}]}

    result = map_product_video(product, detail, inventory, settings(tmp_path))

    assert result["arquivo_video"] == "preferred.mp4"
    assert result["origem_match"] == "tiny_attachment"
    assert result["status"] == "mapped"


def test_exact_case_insensitive_sku_match(tmp_path):
    inventory = touch(tmp_path, "sku-ABC.mp4")
    product = {"id": 123, "sku": "SKU-abc"}

    result = map_product_video(product, {}, inventory, settings(tmp_path))

    assert result["arquivo_video"] == "sku-ABC.mp4"
    assert result["origem_match"] == "sku"


def test_tiny_id_is_used_after_sku(tmp_path):
    inventory = touch(tmp_path, "123456.mp4")
    product = {"id": 123456, "sku": "NO-FILE"}

    result = map_product_video(product, {}, inventory, settings(tmp_path))

    assert result["arquivo_video"] == "123456.mp4"
    assert result["origem_match"] == "id_tiny"


def test_alias_is_last_deterministic_source(tmp_path):
    inventory = touch(tmp_path, "custom-file.mp4")
    product = {"id": 123, "sku": "SKU-X"}

    result = map_product_video(
        product,
        {},
        inventory,
        settings(tmp_path),
        aliases={"SKU-X": "custom-file.mp4"},
    )

    assert result["arquivo_video"] == "custom-file.mp4"
    assert result["origem_match"] == "alias"


def test_duplicate_stem_is_ambiguous_and_never_guessed(tmp_path):
    inventory = touch(tmp_path, "SKU-1.mp4", "SKU-1.webm")
    product = {"id": 123, "sku": "SKU-1"}

    result = map_product_video(product, {}, inventory, settings(tmp_path))

    assert result["status"] == "ambiguous"
    assert result["url_video_completa"] is None
    assert result["arquivo_video"] is None


def test_missing_product_is_preserved_in_output(tmp_path):
    inventory = touch(tmp_path, "unrelated.mp4")
    product = {"id": 123, "sku": "SKU-1"}

    result = map_product_video(product, {}, inventory, settings(tmp_path))

    assert result == {
        "id_tiny": "123",
        "sku": "SKU-1",
        "url_video_completa": None,
        "arquivo_video": None,
        "origem_match": None,
        "status": "missing",
    }


def test_explicit_public_shopvivaliz_url_can_map_without_local_inventory(tmp_path):
    inventory = build_video_inventory(tmp_path / "missing-dir")
    product = {"id": 123, "sku": "SKU-1"}
    detail = {"video": "https://shopvivaliz.com.br/uploads/videos-produtos/from-tiny.mp4"}

    result = map_product_video(product, detail, inventory, settings(tmp_path))

    assert result["status"] == "mapped"
    assert result["arquivo_video"] == "from-tiny.mp4"
    assert result["origem_match"] == "tiny_attachment"
