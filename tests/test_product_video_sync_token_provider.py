import json
from pathlib import Path

import pytest

from scripts.product_video_sync.config import Settings
from scripts.product_video_sync.token_provider import TokenResolutionError, resolve_access_token


def make_settings(tmp_path: Path, token_file: Path | None = None) -> Settings:
    return Settings(
        tiny_api_base_url="https://api.tiny.com.br/public-api/v3",
        video_base_url="https://shopvivaliz.com.br/uploads/videos-produtos/",
        video_dir=tmp_path / "videos",
        token_file=token_file or tmp_path / "tokens.json",
        timeout_seconds=20.0,
        max_retries=3,
        page_size=100,
        allow_playwright_fallback=False,
    )


def test_token_store_has_priority_over_environment(monkeypatch, tmp_path):
    token_file = tmp_path / "tokens.json"
    token_file.write_text(json.dumps({"OLIST_ACCESS_TOKEN": "store-token"}), encoding="utf-8")
    monkeypatch.setenv("OLIST_ACCESS_TOKEN", "env-token")

    token = resolve_access_token(make_settings(tmp_path, token_file))

    assert token == "store-token"


def test_falls_back_to_canonical_environment_token(monkeypatch, tmp_path):
    monkeypatch.setenv("TINY_ACCESS_TOKEN", "tiny-env-token")

    token = resolve_access_token(make_settings(tmp_path))

    assert token == "tiny-env-token"


def test_invalid_token_store_fails_closed_when_no_env(monkeypatch, tmp_path):
    token_file = tmp_path / "tokens.json"
    token_file.write_text("{broken-json", encoding="utf-8")
    monkeypatch.delenv("OLIST_ACCESS_TOKEN", raising=False)
    monkeypatch.delenv("TINY_ACCESS_TOKEN", raising=False)

    with pytest.raises(TokenResolutionError) as exc:
        resolve_access_token(make_settings(tmp_path, token_file))

    assert "broken-json" not in str(exc.value)


def test_missing_token_error_does_not_expose_environment_values(monkeypatch, tmp_path):
    monkeypatch.delenv("OLIST_ACCESS_TOKEN", raising=False)
    monkeypatch.delenv("TINY_ACCESS_TOKEN", raising=False)
    monkeypatch.setenv("OLIST_CLIENT_SECRET", "super-secret-value")

    with pytest.raises(TokenResolutionError) as exc:
        resolve_access_token(make_settings(tmp_path))

    assert "super-secret-value" not in str(exc.value)
