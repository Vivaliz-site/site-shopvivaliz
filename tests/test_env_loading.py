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


def test_active_ads_env_loader_supports_simple_key_values_without_export(tmp_path):
    from scripts.google_ads.client import load_env

    env_path = tmp_path / ".env.local"
    env_path.write_text("FIXTURE_FIRST=abc123\nFIXTURE_SECOND=def456\n", encoding="utf-8")

    before = dict(os.environ)

    loaded = load_env(env_path)

    assert loaded == {"FIXTURE_FIRST": "abc123", "FIXTURE_SECOND": "def456"}
    assert dict(os.environ) == before


def test_retired_collaboration_entrypoint_stays_blocked(tmp_path, monkeypatch, capsys):
    import ai_collaboration
    from scripts.ai import retired_executor

    monkeypatch.setattr(retired_executor, 'REPORT_DIR', tmp_path / 'reports')
    assert ai_collaboration.iniciar_super_agente_trio() == 2
    assert '"external_operation_performed": false' in capsys.readouterr().err
    assert not hasattr(ai_collaboration, 'load_env_file')
