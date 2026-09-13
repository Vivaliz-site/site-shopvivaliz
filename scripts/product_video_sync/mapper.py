from __future__ import annotations

from pathlib import Path
from typing import Any
from urllib.parse import quote, urlparse

from .config import Settings
from .video_inventory import VIDEO_EXTENSIONS, VideoInventory


def _video_filename(value: str) -> str | None:
    parsed = urlparse(value.strip())
    path = parsed.path if parsed.scheme or parsed.netloc else value.strip()
    name = Path(path).name
    return name if Path(name).suffix.casefold() in VIDEO_EXTENSIONS else None


def _collect_video_references(value: Any) -> list[str]:
    found: list[str] = []
    if isinstance(value, str):
        if _video_filename(value):
            found.append(value)
    elif isinstance(value, dict):
        for child in value.values():
            found.extend(_collect_video_references(child))
    elif isinstance(value, (list, tuple)):
        for child in value:
            found.extend(_collect_video_references(child))
    return found


def _base_record(product: dict[str, Any]) -> dict[str, Any]:
    return {
        "id_tiny": str(product.get("id", "")),
        "sku": str(product.get("sku") or ""),
        "url_video_completa": None,
        "arquivo_video": None,
        "origem_match": None,
        "status": "missing",
    }


def _finish(record: dict[str, Any], filename: str, source: str, settings: Settings) -> dict[str, Any]:
    record["arquivo_video"] = filename
    record["url_video_completa"] = settings.video_base_url.rstrip("/") + "/" + quote(filename, safe="/")
    record["origem_match"] = source
    record["status"] = "mapped"
    return record


def _resolve_candidates(candidates: tuple[str, ...], record: dict[str, Any], source: str, settings: Settings):
    unique = tuple(dict.fromkeys(candidates))
    if len(unique) == 1:
        return _finish(record, unique[0], source, settings)
    if len(unique) > 1:
        record["status"] = "ambiguous"
        return record
    return None


def map_product_video(
    product: dict[str, Any],
    detail: dict[str, Any],
    inventory: VideoInventory,
    settings: Settings,
    *,
    aliases: dict[str, str] | None = None,
) -> dict[str, Any]:
    record = _base_record(product)

    explicit_matches: list[str] = []
    base_url = settings.video_base_url.rstrip("/") + "/"
    for reference in _collect_video_references(detail):
        filename = _video_filename(reference)
        if not filename:
            continue
        matches = inventory.match_filename(filename)
        explicit_matches.extend(matches)
        if not matches and not inventory.files and reference.strip().startswith(base_url):
            explicit_matches.append(filename)
    resolved = _resolve_candidates(tuple(explicit_matches), record, "tiny_attachment", settings)
    if resolved is not None:
        return resolved

    sku = str(product.get("sku") or "").strip()
    if sku:
        resolved = _resolve_candidates(inventory.match_stem(sku), record, "sku", settings)
        if resolved is not None:
            return resolved

    product_id = str(product.get("id") or "").strip()
    if product_id:
        resolved = _resolve_candidates(inventory.match_stem(product_id), record, "id_tiny", settings)
        if resolved is not None:
            return resolved

    if aliases:
        alias = aliases.get(sku) if sku else None
        if alias is None and product_id:
            alias = aliases.get(product_id)
        if alias:
            filename = _video_filename(alias) or Path(alias).name
            matches = inventory.match_filename(filename)
            resolved = _resolve_candidates(matches, record, "alias", settings)
            if resolved is not None:
                return resolved

    return record
