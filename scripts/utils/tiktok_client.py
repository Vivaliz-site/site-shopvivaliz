"""
Cliente TikTok Shop Open API.
Secrets necessarios: TIKTOK_APP_KEY, TIKTOK_APP_SECRET,
                     TIKTOK_ACCESS_TOKEN, TIKTOK_SHOP_CIPHER
"""
import hashlib
import hmac
import json
import os
import time
from typing import Generator

import requests
from tenacity import (
    retry,
    retry_if_exception,
    stop_after_attempt,
    wait_exponential,
)

BASE_URL = "https://open-api.tiktokglobalshop.com"


def _is_retryable(exc: Exception) -> bool:
    if isinstance(exc, requests.HTTPError):
        return exc.response is not None and exc.response.status_code in (429, 500, 502, 503, 504)
    return isinstance(exc, (requests.ConnectionError, requests.Timeout))


class TikTokClient:
    def __init__(self):
        self.app_key = os.environ["TIKTOK_APP_KEY"]
        self.app_secret = os.environ["TIKTOK_APP_SECRET"]
        self.access_token = os.environ["TIKTOK_ACCESS_TOKEN"]
        self.shop_cipher = os.environ.get("TIKTOK_SHOP_CIPHER") or os.environ.get("TIKTOK_SHOP_ID", "")
        if not self.shop_cipher:
            raise KeyError("TIKTOK_SHOP_CIPHER")
        self._session = requests.Session()

    def _headers(self, *, content_type: str | None = "application/json") -> dict:
        headers = {"x-tts-access-token": self.access_token}
        if content_type:
            headers["Content-Type"] = content_type
        return headers

    def _sign(self, path: str, params: dict, body: str = "") -> str:
        """
        Assinatura TikTok Shop: HMAC-SHA256
        Concatena: app_secret + path + sorted_params + body + app_secret
        """
        exclude = {"sign", "access_token"}
        sorted_params = sorted((k, str(v)) for k, v in params.items() if k not in exclude)
        param_str = "".join(f"{k}{v}" for k, v in sorted_params)
        sign_str = f"{self.app_secret}{path}{param_str}{body}{self.app_secret}"
        return hmac.new(
            self.app_secret.encode("utf-8"),
            sign_str.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

    def _base_params(self, path: str, extra: dict | None = None, body: str = "") -> dict:
        params: dict = {
            "app_key": self.app_key,
            "shop_cipher": self.shop_cipher,
            "timestamp": str(int(time.time())),
            **(extra or {}),
        }
        params["sign"] = self._sign(path, params, body)
        return params

    @retry(
        retry=retry_if_exception(_is_retryable),
        wait=wait_exponential(multiplier=1, min=2, max=30),
        stop=stop_after_attempt(4),
    )
    def _get(self, path: str, extra_params: dict | None = None) -> dict:
        params = self._base_params(path, extra_params)
        resp = self._session.get(
            f"{BASE_URL}{path}",
            params=params,
            headers=self._headers(content_type=None),
            timeout=30,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise requests.HTTPError(f"{exc} | body={resp.text[:400]}", response=resp) from exc
        data = resp.json()
        code = data.get("code", 0)
        if code not in (0, 200):
            raise RuntimeError(f"TikTok API error {code}: {data.get('message')}")
        return data

    @retry(
        retry=retry_if_exception(_is_retryable),
        wait=wait_exponential(multiplier=1, min=2, max=30),
        stop=stop_after_attempt(4),
    )
    def _post(self, path: str, body: dict, extra_params: dict | None = None) -> dict:
        body_str = json.dumps(body, separators=(",", ":"))
        params = self._base_params(path, extra_params, body_str)
        resp = self._session.post(
            f"{BASE_URL}{path}",
            params=params,
            data=body_str,
            headers=self._headers(),
            timeout=30,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise requests.HTTPError(f"{exc} | body={resp.text[:400]}", response=resp) from exc
        data = resp.json()
        code = data.get("code", 0)
        if code not in (0, 200):
            raise RuntimeError(f"TikTok API error {code}: {data.get('message')}")
        return data

    @retry(
        retry=retry_if_exception(_is_retryable),
        wait=wait_exponential(multiplier=1, min=2, max=30),
        stop=stop_after_attempt(4),
    )
    def _put(self, path: str, body: dict, extra_params: dict | None = None) -> dict:
        body_str = json.dumps(body, separators=(",", ":"))
        params = self._base_params(path, extra_params, body_str)
        resp = self._session.put(
            f"{BASE_URL}{path}",
            params=params,
            data=body_str,
            headers=self._headers(),
            timeout=30,
        )
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise requests.HTTPError(f"{exc} | body={resp.text[:400]}", response=resp) from exc
        data = resp.json()
        code = data.get("code", 0)
        if code not in (0, 200):
            raise RuntimeError(f"TikTok API error {code}: {data.get('message')}")
        return data

    # ── Produtos ──────────────────────────────────────────────────────────────

    def iter_all_products(self, page_size: int = 100) -> Generator[dict, None, None]:
        """Itera por TODOS os produtos com paginação automática."""
        path = "/product/202309/products/search"
        page_token = ""
        while True:
            params: dict = {"page_size": page_size, "version": "202309"}
            if page_token:
                params["page_token"] = page_token
            data = self._post(path, {}, extra_params=params)
            products = data.get("data", {}).get("products", [])
            if not products:
                break
            yield from products
            page_token = data.get("data", {}).get("next_page_token", "")
            if not page_token:
                break
            time.sleep(0.4)

    def get_product_detail(self, product_id: str) -> dict:
        path = f"/product/202309/products/{product_id}"
        return self._get(path).get("data", {})

    # ── Atualização ───────────────────────────────────────────────────────────

    def update_product(
        self,
        product_id: str,
        *,
        title: str | None = None,
        description: str | None = None,
        image_urls: list[str] | None = None,
    ) -> dict:
        """Atualiza título, descrição e/ou imagens. NUNCA altera preço."""
        body: dict = {"product_id": product_id}
        if title:
            body["title"] = title[:255]
        if description:
            body["description"] = description
        if image_urls:
            body["main_images"] = [{"url": u} for u in image_urls]
        return self._put("/api/products", body)

    # ── Métricas ──────────────────────────────────────────────────────────────

    def get_product_metrics(self, product_ids: list[str]) -> list[dict]:
        """Busca métricas de CTR/views por produto."""
        try:
            data = self._post("/api/analytics/product", {"product_id_list": product_ids})
            return data.get("data", {}).get("product_metric_list", [])
        except Exception:
            return []

    # ── Imagens ───────────────────────────────────────────────────────────────

    def upload_image(self, local_path: str) -> str:
        """Faz upload de imagem e retorna URL pública no CDN TikTok."""
        path = "/api/upload/image"
        params = self._base_params(path)
        with open(local_path, "rb") as f:
            resp = self._session.post(
                f"{BASE_URL}{path}",
                params=params,
                headers=self._headers(content_type=None),
                files={"file": f},
                timeout=60,
            )
        resp.raise_for_status()
        return resp.json()["data"]["url"]
