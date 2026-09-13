from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

VIDEO_EXTENSIONS = {".mp4", ".mov", ".m4v", ".webm", ".avi", ".mkv"}


def _key(value: str) -> str:
    return value.strip().casefold()


@dataclass(frozen=True)
class VideoInventory:
    files: tuple[str, ...]
    by_stem: dict[str, tuple[str, ...]]
    by_name: dict[str, tuple[str, ...]]

    def match_stem(self, value: str) -> tuple[str, ...]:
        return self.by_stem.get(_key(value), ())

    def match_filename(self, value: str) -> tuple[str, ...]:
        name = Path(value).name
        return self.by_name.get(_key(name), ())


def build_video_inventory(path: Path) -> VideoInventory:
    if not path.exists() or not path.is_dir():
        return VideoInventory(files=(), by_stem={}, by_name={})

    files: list[str] = []
    by_stem_mut: dict[str, list[str]] = {}
    by_name_mut: dict[str, list[str]] = {}

    for candidate in sorted(path.rglob("*")):
        if not candidate.is_file() or candidate.suffix.casefold() not in VIDEO_EXTENSIONS:
            continue
        relative = candidate.relative_to(path).as_posix()
        files.append(relative)
        by_stem_mut.setdefault(_key(candidate.stem), []).append(relative)
        by_name_mut.setdefault(_key(candidate.name), []).append(relative)

    return VideoInventory(
        files=tuple(files),
        by_stem={key: tuple(values) for key, values in by_stem_mut.items()},
        by_name={key: tuple(values) for key, values in by_name_mut.items()},
    )
