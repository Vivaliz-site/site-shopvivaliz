"""
Cliente Shopee Partner API v2.
Secrets necessários: SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY,
                     SHOPEE_ACCESS_TOKEN, SHOPEE_SHOP_ID
"""
import hashlib
import hmac
import os
import time
from pathlib import Path
from typing import Generator

import requests
from tenacity import (
    retry,
    retry_if_exception,
    stop_after_attempt,
    wait_exponential,
)

BASE_URL = "https://partner.shopeemobile.com/api/v2"


def _is_retryable(exc: Exception) -> bool:
    if isinstance(exc, requests.HTTPError):
        return exc.response is not None and exc.response.status_code in (429, 500, 502, 503, 504)
    return isinstance(exc, (requests.ConnectionError, requests.Timeout))


class ShopeeClient:
    def __init__(self):
        # Aceita tanto SHOPEE_PARTNER_ID quanto SHOPEE_TEST_PARTNER_ID
        pid = os.environ.get("SHOPEE_PARTNER_ID") or os.environ.get("SHOPEE_TEST_PARTNER_ID")
        pkey = os.environ.get("SHOPEE_PARTNER_KEY") or os.environ.get("SHOPEE_TEST_PARTNER_KEY")
        if not pid or not pkey:
            raise RuntimeError("Secret SHOPEE_PARTNER_ID (ou SHOPEE_TEST_PARTNER_ID) não configurado")
        self.partner_id = int(pid)
        self.partner_key = pkey
        self.access_token = os.environ["SHOPEE_ACCESS_TOKEN"]
        self.shop_id = int(os.environ["SHOPEE_SHOP_ID"])
        self._session = requests.Session()
        self._session.headers.update({"Content-Type": "application/json"})

    def _sign(self, path: str, timestamp: int) -> str:
        base = f"{self.partner_id}{path}{timestamp}{self.access_token}{self.shop_id}"
        return hmac.new(
            self.partner_key.encode("utf-8"),
            base.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

    def _base_params(self, path: str) -> dict:
        ts = int(time.time())
        return {
            "partner_id": self.partner_id,
            "timestamp": ts,
            "access_token": self.access_token,
            "shop_id": self.shop_id,
            "sign": self._sign(path, ts),
        }

    @retry(
        retry=retry_if_exception(_is_retryable),
        wait=wait_exponential(multiplier=1, min=2, max=30),
        stop=stop_after_attempt(4),
    )
    def _get(self, path: str, extra_params: dict | None = None) -> dict:
        params = {**self._base_params(path), **(extra_params or {})}
        resp = self._session.get(f"{BASE_URL}{path}", params=params, timeout=30)
        resp.raise_for_status()
        data = resp.json()
        if data.get("error"):
            raise RuntimeError(f"Shopee API error {data['error']}: {data.get('message')}")
        return data

    @retry(
        retry=retry_if_exception(_is_retryable),
        wait=wait_exponential(multiplier=1, min=2, max=30),
        stop=stop_after_attempt(4),
    )
    def _post(self, path: str, body: dict, extra_params: dict | None = None) -> dict:
        params = {**self._base_params(path), **(extra_params or {})}
        resp = self._session.post(f"{BASE_URL}{path}", params=params, json=body, timeout=30)
        resp.raise_for_status()
        data = resp.json()
        if data.get("error"):
            raise RuntimeError(f"Shopee API error {data['error']}: {data.get('message')}")
        return data

    # ── Produtos ──────────────────────────────────────────────────────────────

    def iter_all_products(self, page_size: int = 100) -> Generator[dict, None, None]:
        """Itera por TODOS os produtos da loja com paginação automática."""
        path = "/product/get_item_list"
        offset = 0
        while True:
            data = self._get(path, {
                "offset": offset,
                "page_size": page_size,
                "item_status": "NORMAL",
            })
            items = data.get("response", {}).get("item", [])
            if not items:
                break
            yield from items
            if not data.get("response", {}).get("has_next_page"):
                break
            offset += page_size
            time.sleep(0.4)

    def get_product_details(self, item_ids: list[int]) -> list[dict]:
        """Busca detalhes completos em lotes de 50."""
        path = "/product/get_item_base_info"
        results = []
        for i in range(0, len(item_ids), 50):
            batch = item_ids[i : i + 50]
            data = self._get(path, {"item_id_list": ",".join(str(x) for x in batch)})
            results.extend(data.get("response", {}).get("item_list", []))
            time.sleep(0.3)
        return results

    def get_product_metrics(self, item_ids: list[int]) -> list[dict]:
        """Busca métricas de performance (CTR, views, sales)."""
        path = "/analytics/get_shop_performance"
        try:
            data = self._get(path)
            return data.get("response", {}).get("item_performance_list", [])
        except Exception:
            return []

    # ── Atualização ───────────────────────────────────────────────────────────

    def update_product(
        self,
        item_id: int,
        *,
        title: str | None = None,
        description: str | None = None,
        image_ids: list[str] | None = None,
    ) -> dict:
        """Atualiza título, descrição e/ou imagens. NUNCA altera preço."""
        body: dict = {"item_id": item_id}
        if title:
            body["item_name"] = title[:120]
        if description:
            body["description"] = description
        if image_ids:
            body["image"] = {"image_id_list": image_ids}
        return self._post("/product/update_item", body)

    # ── Imagens ───────────────────────────────────────────────────────────────

    def upload_image(self, local_path: str) -> str:
        """Faz upload de imagem local para Shopee e retorna image_id."""
        path = "/media_space/upload_image"
        params = self._base_params(path)
        with open(local_path, "rb") as f:
            resp = self._session.post(
                f"{BASE_URL}{path}",
                params=params,
                files={"image": (Path(local_path).name, f, "image/jpeg")},
                timeout=60,
            )
        resp.raise_for_status()
        return resp.json()["response"]["image_id"]

    def upload_image_by_url(self, url: str) -> str:
        """Faz upload de imagem via URL para Shopee e retorna image_id."""
        path = "/media_space/upload_image_by_url"
        data = self._post(path, {"url": url})
        return data["response"]["image_id"]
