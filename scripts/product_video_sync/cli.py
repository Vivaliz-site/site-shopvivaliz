from __future__ import annotations

import argparse
import json
import os
import tempfile
from dataclasses import replace
from pathlib import Path
from typing import Any

from .config import Settings
from .mapper import map_product_video
from .tiny_client import TinyClient
from .token_provider import resolve_access_token
from .video_inventory import build_video_inventory


def write_json_atomic(destination: Path, data: Any) -> None:
    destination = destination.resolve()
    destination.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(
        prefix=f".{destination.name}.", suffix=".tmp", dir=str(destination.parent)
    )
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(data, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_name, destination)
    except Exception:
        try:
            os.unlink(temp_name)
        except FileNotFoundError:
            pass
        raise


def load_aliases(path: Path | None) -> dict[str, str]:
    if path is None or not path.exists():
        return {}
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("Alias file must contain a JSON object")
    return {str(key): str(value) for key, value in payload.items() if str(value).strip()}


def run_mapping(
    settings: Settings,
    client: TinyClient | Any,
    *,
    output_path: Path,
    aliases: dict[str, str] | None = None,
) -> dict[str, int]:
    inventory = build_video_inventory(settings.video_dir)
    results: list[dict[str, Any]] = []

    for product in client.list_active_products():
        product_id = product.get("id")
        if product_id is None:
            detail: dict[str, Any] = dict(product)
            attachments: list[dict[str, Any]] = []
        else:
            raw_detail = client.get_product(product_id)
            detail = raw_detail if isinstance(raw_detail, dict) else {}
            attachments = client.get_attachments(product_id)
        source_data = {"produto": detail, "anexos": attachments}
        results.append(
            map_product_video(
                product,
                source_data,
                inventory,
                settings,
                aliases=aliases,
            )
        )

    write_json_atomic(output_path, results)
    return {
        "total": len(results),
        "mapped": sum(item["status"] == "mapped" for item in results),
        "missing": sum(item["status"] == "missing" for item in results),
        "ambiguous": sum(item["status"] == "ambiguous" for item in results),
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Mapeia produtos Tiny v3 para vídeos ShopVivaLiz")
    parser.add_argument("--output", type=Path, default=Path("storage/tiny/produtos_videos_mapeados.json"))
    parser.add_argument("--video-dir", type=Path, default=None)
    parser.add_argument("--aliases", type=Path, default=None)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    settings = Settings.from_env()
    if args.video_dir is not None:
        settings = replace(settings, video_dir=args.video_dir)

    alias_path = args.aliases
    if alias_path is None:
        configured = os.getenv("PRODUCT_VIDEO_ALIAS_FILE", "").strip()
        alias_path = Path(configured) if configured else None

    token = resolve_access_token(settings)
    client = TinyClient(settings, token)
    summary = run_mapping(
        settings,
        client,
        output_path=args.output,
        aliases=load_aliases(alias_path),
    )
    print(json.dumps(summary, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
