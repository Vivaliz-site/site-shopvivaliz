#!/usr/bin/env python3
from __future__ import annotations

import os
import shutil
import tempfile
from pathlib import Path
from urllib.parse import quote

DEFAULT_SITE_URL = "https://shopvivaliz.com.br"


class PublicStorageError(RuntimeError):
    pass


def project_root() -> Path:
    override = (os.getenv("SHOPVIVALIZ_PUBLIC_ROOT") or "").strip()
    if override:
        return Path(override).expanduser().resolve()
    return Path(__file__).resolve().parents[1]


def normalize_relative_path(relative_path: str) -> Path:
    raw = str(relative_path or "").replace("\\", "/").strip().strip("/")
    if not raw:
        raise PublicStorageError("public_path_empty")
    parts = [part for part in raw.split("/") if part not in ("", ".")]
    if any(part == ".." for part in parts):
        raise PublicStorageError("public_path_traversal_rejected")
    path = Path(*parts)
    if path.is_absolute():
        raise PublicStorageError("public_path_absolute_rejected")
    return path


def public_url(relative_path: str, *, base_url: str | None = None) -> str:
    rel = normalize_relative_path(relative_path)
    base = (base_url or os.getenv("SITE_BASE_URL") or DEFAULT_SITE_URL).rstrip("/")
    encoded = "/".join(quote(part) for part in rel.parts)
    return f"{base}/{encoded}"


def publish_file(
    source: str | Path,
    relative_path: str,
    *,
    base_url: str | None = None,
    mode: int = 0o644,
) -> str:
    src = Path(source)
    if not src.is_file():
        raise PublicStorageError(f"source_file_missing:{src}")

    rel = normalize_relative_path(relative_path)
    root = project_root()
    target = root / rel
    target.parent.mkdir(parents=True, exist_ok=True)

    try:
        if src.resolve() == target.resolve(strict=False):
            os.chmod(target, mode)
            return public_url(str(rel), base_url=base_url)
    except OSError:
        pass

    fd, temp_name = tempfile.mkstemp(prefix=f".{target.name}.", dir=str(target.parent))
    temp_path = Path(temp_name)
    try:
        with os.fdopen(fd, "wb") as out, src.open("rb") as inp:
            shutil.copyfileobj(inp, out, length=1024 * 1024)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp_path, mode)
        os.replace(temp_path, target)
    except Exception as exc:
        temp_path.unlink(missing_ok=True)
        raise PublicStorageError(f"public_publish_failed:{type(exc).__name__}") from exc

    return public_url(str(rel), base_url=base_url)
