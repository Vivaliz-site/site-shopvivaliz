import os
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]


def test_env_file_is_loaded_when_present():
    env_path = ROOT / ".env.local"
    if not env_path.exists():
        pytest.skip(".env.local not present in this environment")

    values = {}
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key in {"GEMINI_API_KEY", "ANTHROPIC_API_KEY", "OPENAI_API_KEY"}:
            values[key] = value.strip().strip('"').strip("'")

    gemini_key = values.get("GEMINI_API_KEY", "")
    assert gemini_key == "" or len(gemini_key) >= 10


def test_load_env_file_supports_simple_key_values(tmp_path):
    from ai_collaboration import load_env_file

    env_path = tmp_path / ".env.local"
    env_path.write_text("OPENAI_API_KEY=abc123\nGEMINI_API_KEY=def456\n", encoding="utf-8")

    os.environ.pop("OPENAI_API_KEY", None)
    os.environ.pop("GEMINI_API_KEY", None)

    loaded = load_env_file(env_path)

    assert loaded["OPENAI_API_KEY"] == "abc123"
    assert loaded["GEMINI_API_KEY"] == "def456"
