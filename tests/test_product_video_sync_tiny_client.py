from pathlib import Path

import pytest

from scripts.product_video_sync.config import Settings
from scripts.product_video_sync.tiny_client import TinyApiError, TinyClient


class FakeResponse:
    def __init__(self, status_code=200, payload=None, headers=None, text=""):
        self.status_code = status_code
        self._payload = payload if payload is not None else {}
        self.headers = headers or {}
        self.text = text

    def json(self):
        return self._payload


class FakeSession:
    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = []

    def get(self, url, **kwargs):
        self.calls.append((url, kwargs))
        if not self.responses:
            raise AssertionError("unexpected HTTP call")
        return self.responses.pop(0)


def settings(tmp_path, *, page_size=2, retries=2):
    return Settings(
        tiny_api_base_url="https://api.tiny.com.br/public-api/v3",
        video_base_url="https://shopvivaliz.com.br/uploads/videos-produtos/",
        video_dir=Path(tmp_path),
        token_file=Path(tmp_path) / "tokens.json",
        timeout_seconds=7.0,
        max_retries=retries,
        page_size=page_size,
        allow_playwright_fallback=False,
    )


def test_lists_active_products_with_limit_offset_and_bearer(tmp_path):
    session = FakeSession([
        FakeResponse(payload={"itens": [{"id": 1, "sku": "A"}, {"id": 2, "sku": "B"}], "paginacao": {"total": 3}}),
        FakeResponse(payload={"itens": [{"id": 3, "sku": "C"}], "paginacao": {"total": 3}}),
    ])
    client = TinyClient(settings(tmp_path), "secret-token", session=session, sleeper=lambda _: None)

    products = client.list_active_products()

    assert [p["id"] for p in products] == [1, 2, 3]
    assert session.calls[0][1]["params"] == {"situacao": "A", "limit": 2, "offset": 0}
    assert session.calls[1][1]["params"]["offset"] == 2
    assert session.calls[0][1]["headers"]["Authorization"] == "Bearer secret-token"
    assert session.calls[0][1]["timeout"] == 7.0


def test_stops_if_api_repeats_same_ids_to_prevent_pagination_loop(tmp_path):
    repeated = {"itens": [{"id": 1}, {"id": 2}], "paginacao": {"total": 999}}
    session = FakeSession([FakeResponse(payload=repeated), FakeResponse(payload=repeated)])
    client = TinyClient(settings(tmp_path), "token", session=session, sleeper=lambda _: None)

    products = client.list_active_products()

    assert [p["id"] for p in products] == [1, 2]
    assert len(session.calls) == 2


def test_retries_429_and_respects_retry_after(tmp_path):
    sleeps = []
    session = FakeSession([
        FakeResponse(status_code=429, headers={"Retry-After": "0"}),
        FakeResponse(payload={"itens": [], "paginacao": {"total": 0}}),
    ])
    client = TinyClient(settings(tmp_path), "token", session=session, sleeper=sleeps.append)

    assert client.list_active_products() == []
    assert sleeps == [0.0]
    assert len(session.calls) == 2


def test_retries_429_uses_rate_limit_reset_when_retry_after_is_missing(tmp_path):
    sleeps = []
    session = FakeSession([
        FakeResponse(status_code=429, headers={"X-RateLimit-Reset": "7"}),
        FakeResponse(payload={"itens": [], "paginacao": {"total": 0}}),
    ])
    client = TinyClient(settings(tmp_path), "token", session=session, sleeper=sleeps.append)

    assert client.list_active_products() == []
    assert sleeps == [7.0]


def test_retries_server_error_with_finite_backoff(tmp_path):
    sleeps = []
    session = FakeSession([
        FakeResponse(status_code=503),
        FakeResponse(payload={"id": 10, "sku": "ABC"}),
    ])
    client = TinyClient(settings(tmp_path, retries=2), "token", session=session, sleeper=sleeps.append)

    product = client.get_product(10)

    assert product["id"] == 10
    assert sleeps == [1.0]


def test_401_fails_without_retry_and_does_not_echo_token(tmp_path):
    session = FakeSession([FakeResponse(status_code=401, text="unauthorized")])
    client = TinyClient(settings(tmp_path), "very-secret-token", session=session, sleeper=lambda _: None)

    with pytest.raises(TinyApiError) as exc:
        client.get_product(10)

    assert len(session.calls) == 1
    assert "very-secret-token" not in str(exc.value)


def test_get_attachments_uses_official_product_attachments_endpoint(tmp_path):
    session = FakeSession([
        FakeResponse(payload=[{"id": 99, "url": "https://example.com/video.mp4", "externo": True}])
    ])
    client = TinyClient(settings(tmp_path), "token", session=session, sleeper=lambda _: None)

    attachments = client.get_attachments(10)

    assert attachments == [{"id": 99, "url": "https://example.com/video.mp4", "externo": True}]
    assert session.calls[0][0].endswith("/produtos/10/anexos")
