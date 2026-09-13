import json
from pathlib import Path

from scripts.product_video_sync.cli import run_mapping, write_json_atomic
from scripts.product_video_sync.config import Settings


class FakeTinyClient:
    def __init__(self):
        self.detail_calls = []

    def list_active_products(self):
        return [
            {"id": 1, "sku": "SKU-1"},
            {"id": 2, "sku": "SKU-2"},
        ]

    def get_product(self, product_id):
        self.detail_calls.append(product_id)
        if product_id == 1:
            return {"id": 1, "sku": "SKU-1", "anexos": []}
        return {"id": 2, "sku": "SKU-2"}


def settings(tmp_path):
    return Settings(
        tiny_api_base_url="https://api.tiny.com.br/public-api/v3",
        video_base_url="https://shopvivaliz.com.br/uploads/videos-produtos/",
        video_dir=tmp_path / "videos",
        token_file=tmp_path / "tokens.json",
        timeout_seconds=20,
        max_retries=2,
        page_size=100,
        allow_playwright_fallback=False,
    )


def test_write_json_atomic_replaces_destination_and_leaves_no_temp(tmp_path):
    destination = tmp_path / "produtos_videos_mapeados.json"
    destination.write_text("old", encoding="utf-8")

    write_json_atomic(destination, [{"id_tiny": "1"}])

    assert json.loads(destination.read_text(encoding="utf-8")) == [{"id_tiny": "1"}]
    assert list(tmp_path.glob("*.tmp")) == []


def test_run_mapping_generates_requested_schema_and_preserves_missing(tmp_path):
    video_dir = tmp_path / "videos"
    video_dir.mkdir()
    (video_dir / "SKU-1.mp4").write_bytes(b"video")
    output = tmp_path / "produtos_videos_mapeados.json"
    client = FakeTinyClient()
    cfg = settings(tmp_path)

    summary = run_mapping(cfg, client, output_path=output)

    data = json.loads(output.read_text(encoding="utf-8"))
    assert client.detail_calls == [1, 2]
    assert data[0] == {
        "id_tiny": "1",
        "sku": "SKU-1",
        "url_video_completa": "https://shopvivaliz.com.br/uploads/videos-produtos/SKU-1.mp4",
        "arquivo_video": "SKU-1.mp4",
        "origem_match": "sku",
        "status": "mapped",
    }
    assert data[1]["id_tiny"] == "2"
    assert data[1]["status"] == "missing"
    assert summary == {"total": 2, "mapped": 1, "missing": 1, "ambiguous": 0}


def test_run_mapping_uses_aliases_without_marketplace_side_effects(tmp_path):
    video_dir = tmp_path / "videos"
    video_dir.mkdir()
    (video_dir / "custom.mp4").write_bytes(b"video")
    output = tmp_path / "produtos_videos_mapeados.json"
    client = FakeTinyClient()

    summary = run_mapping(
        settings(tmp_path),
        client,
        output_path=output,
        aliases={"SKU-2": "custom.mp4"},
    )

    data = json.loads(output.read_text(encoding="utf-8"))
    assert data[1]["origem_match"] == "alias"
    assert summary["mapped"] == 1


def test_default_output_uses_already_ignored_runtime_storage():
    from scripts.product_video_sync.cli import build_parser

    args = build_parser().parse_args([])
    assert args.output == Path("storage/tiny/produtos_videos_mapeados.json")
