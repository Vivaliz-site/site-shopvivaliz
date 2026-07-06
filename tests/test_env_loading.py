import os
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]


def test_env_file_is_loaded_when_present():
    env_path = ROOT / ".env.local"
    assert env_path.exists(), "Expected .env.local to exist"

    values = {}
    for line in env_path.read_text(encoding="utf-8").splitlines():
        if not line or line.startswith("#"):
            continue
        if "=" in line:
            key, value = line.split("=", 1)
            if key.strip() in {"GEMINI_API_KEY", "ANTHROPIC_API_KEY", "OPENAI_API_KEY"}:
                values[key.strip()] = value.strip().strip('"')

    assert values["GEMINI_API_KEY"] == "" or len(values["GEMINI_API_KEY"]) >= 10


def test_load_env_file_supports_simple_key_values(tmp_path):
    from ai_collaboration import load_env_file

    env_path = tmp_path / ".env.local"
    env_path.write_text("OPENAI_API_KEY=abc123\nGEMINI_API_KEY=def456\n", encoding="utf-8")

    os.environ.pop("OPENAI_API_KEY", None)
    os.environ.pop("GEMINI_API_KEY", None)

    loaded = load_env_file(env_path)

    assert loaded["OPENAI_API_KEY"] == "abc123"
    assert loaded["GEMINI_API_KEY"] == "def456"
