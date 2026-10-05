from __future__ import annotations
from dataclasses import dataclass, field
from pathlib import Path
import tomllib
from .domain import Mode, PilotLimits


@dataclass(frozen=True)
class RuntimeConfig:
    mode: Mode = Mode.PAPER
    limits: PilotLimits = field(default_factory=PilotLimits)
    scanner_interval_seconds: int = 5
    max_candidates: int = 12

    @classmethod
    def load(cls, path: Path) -> "RuntimeConfig":
        data = tomllib.loads(path.read_text(encoding="utf-8"))
        runtime = data.get("runtime", {})
        mode = Mode(runtime.get("mode", "PAPER"))
        if mode not in (Mode.SHADOW, Mode.PAPER):
            raise ValueError("only SHADOW and PAPER are supported")
        return cls(
            mode=mode,
            scanner_interval_seconds=int(runtime.get("scanner_interval_seconds", 5)),
            max_candidates=int(runtime.get("max_candidates", 12)),
        )
