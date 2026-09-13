from __future__ import annotations

import time
from collections.abc import Callable
from typing import Any

import requests

from .config import Settings


class TinyApiError(RuntimeError):
    """Safe API error that never includes credentials."""


class TinyClient:
    def __init__(
        self,
        settings: Settings,
        access_token: str,
        *,
        session: requests.Session | Any | None = None,
        sleeper: Callable[[float], None] = time.sleep,
    ) -> None:
        self.settings = settings
        self._token = access_token
        self._session = session or requests.Session()
        self._sleep = sleeper

    def _headers(self) -> dict[str, str]:
        return {
            "Authorization": f"Bearer {self._token}",
            "Accept": "application/json",
        }

    def _get_json(self, path: str, *, params: dict[str, Any] | None = None) -> dict[str, Any]:
        url = f"{self.settings.tiny_api_base_url.rstrip('/')}/{path.lstrip('/')}"
        attempts = self.settings.max_retries + 1
        for attempt in range(attempts):
            try:
                response = self._session.get(
                    url,
                    headers=self._headers(),
                    params=params,
                    timeout=self.settings.timeout_seconds,
                )
            except requests.RequestException as exc:
                if attempt + 1 >= attempts:
                    raise TinyApiError("Tiny API request failed after finite retries") from exc
                self._sleep(float(2**attempt))
                continue

            status = int(response.status_code)
            if status == 429 or 500 <= status <= 599:
                if attempt + 1 >= attempts:
                    raise TinyApiError(f"Tiny API unavailable after retries (HTTP {status})")
                if status == 429:
                    retry_after = response.headers.get("Retry-After")
                    try:
                        delay = float(retry_after) if retry_after is not None else float(2**attempt)
                    except (TypeError, ValueError):
                        delay = float(2**attempt)
                else:
                    delay = float(2**attempt)
                self._sleep(max(0.0, delay))
                continue

            if status in {401, 403}:
                raise TinyApiError(f"Tiny API authentication/permission failure (HTTP {status})")
            if status < 200 or status >= 300:
                raise TinyApiError(f"Tiny API request failed (HTTP {status})")

            try:
                payload = response.json()
            except (ValueError, TypeError) as exc:
                raise TinyApiError("Tiny API returned invalid JSON") from exc
            if not isinstance(payload, dict):
                raise TinyApiError("Tiny API returned an unexpected JSON structure")
            return payload

        raise TinyApiError("Tiny API retry loop exhausted")

    def list_active_products(self) -> list[dict[str, Any]]:
        products: list[dict[str, Any]] = []
        seen_ids: set[str] = set()
        offset = 0
        limit = self.settings.page_size

        while True:
            payload = self._get_json(
                "/produtos",
                params={"situacao": "A", "limit": limit, "offset": offset},
            )
            items = payload.get("itens") or []
            if not isinstance(items, list) or not items:
                break

            new_count = 0
            for item in items:
                if not isinstance(item, dict):
                    continue
                product_id = item.get("id")
                key = str(product_id) if product_id is not None else f"offset:{offset}:idx:{len(products)}"
                if key in seen_ids:
                    continue
                seen_ids.add(key)
                products.append(item)
                new_count += 1

            if new_count == 0:
                break

            pagination = payload.get("paginacao") if isinstance(payload.get("paginacao"), dict) else {}
            total = pagination.get("total")
            if isinstance(total, int) and len(products) >= total:
                break
            if len(items) < limit:
                break
            offset += limit

        return products

    def get_product(self, product_id: int | str) -> dict[str, Any]:
        return self._get_json(f"/produtos/{product_id}")

    def get_attachments(self, product_id: int | str) -> list[dict[str, Any]]:
        detail = self.get_product(product_id)
        attachments = detail.get("anexos") or []
        if not isinstance(attachments, list):
            return []
        return [item for item in attachments if isinstance(item, dict)]
