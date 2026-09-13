from pathlib import Path

import pytest

from scripts.product_video_sync.config import Settings
from scripts.product_video_sync.playwright_fallback import (
    PlaywrightFallback,
    PlaywrightFallbackDisabled,
    PlaywrightFallbackUnavailable,
)


def settings(tmp_path, enabled=False):
    return Settings(
        tiny_api_base_url="https://api.tiny.com.br/public-api/v3",
        video_base_url="https://shopvivaliz.com.br/uploads/videos-produtos/",
        video_dir=Path(tmp_path),
        token_file=Path(tmp_path) / "tokens.json",
        timeout_seconds=20,
        max_retries=2,
        page_size=100,
        allow_playwright_fallback=enabled,
    )


def test_playwright_fallback_is_disabled_by_default(tmp_path):
    fallback = PlaywrightFallback(settings(tmp_path, enabled=False))

    with pytest.raises(PlaywrightFallbackDisabled):
        fallback.lookup({"id": 123, "sku": "SKU-1"})


def test_enabled_fallback_without_runtime_resolver_fails_explicitly(tmp_path):
    fallback = PlaywrightFallback(settings(tmp_path, enabled=True))

    with pytest.raises(PlaywrightFallbackUnavailable):
        fallback.lookup({"id": 123, "sku": "SKU-1"})


def test_enabled_fallback_calls_only_explicit_resolver(tmp_path):
    calls = []

    def resolver(product):
        calls.append(product["id"])
        return {"video": "SKU-1.mp4"}

    fallback = PlaywrightFallback(settings(tmp_path, enabled=True), resolver=resolver)

    assert fallback.lookup({"id": 123, "sku": "SKU-1"}) == {"video": "SKU-1.mp4"}
    assert calls == [123]
