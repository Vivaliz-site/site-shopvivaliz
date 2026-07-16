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

DEFAULT_BASE_URL = "https://partner.shopeemobile.com/api/v2"
SANDBOX_BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg/api/v2"


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
        self.refresh_token = os.environ.get("SHOPEE_REFRESH_TOKEN", "")
        self.shop_id = int(os.environ["SHOPEE_SHOP_ID"])
        self.base_url = self._resolve_base_url()
        self._session = requests.Session()
        if self.refresh_token:
            self._refresh_access_token()

    @staticmethod
    def _is_invalid_token_response(resp: requests.Response) -> bool:
        try:
            data = resp.json()
        except ValueError:
            return False
        error = str(data.get("error", "")).lower()
        return error in {"invalid_access_token", "invalid_acceess_token", "access_token_expired"}

    def _resolve_base_url(self) -> str:
        configured = (os.environ.get("SHOPEE_BASE_URL") or "").strip().rstrip("/")
        if configured:
            return configured
        if os.environ.get("SHOPEE_TEST_PARTNER_ID") or os.environ.get("SHOPEE_TEST_PARTNER_KEY"):
            return SANDBOX_BASE_URL
        return DEFAULT_BASE_URL

    def _sign(self, path: str, timestamp: int) -> str:
        api_path = self._signed_path(path)
        base = f"{self.partner_id}{api_path}{timestamp}{self.access_token}{self.shop_id}"
        return hmac.new(
            self.partner_key.encode("utf-8"),
            base.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

    def _auth_sign(self, path: str, timestamp: int) -> str:
        api_path = self._signed_path(path)
        base = f"{self.partner_id}{api_path}{timestamp}"
        return hmac.new(
            self.partner_key.encode("utf-8"),
            base.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

    @staticmethod
    def _signed_path(path: str) -> str:
        return path if path.startswith("/api/") else f"/api/v2{path}"

    def _base_params(self, path: str) -> dict:
        ts = int(time.time())
        return {
            "partner_id": self.partner_id,
            "timestamp": ts,
            "access_token": self.access_token,
            "shop_id": self.shop_id,
            "sign": self._sign(path, ts),
        }

    def _refresh_access_token(self) -> None:
        path = "/auth/access_token/get"
        timestamp = int(time.time())
        params = {
            "partner_id": self.partner_id,
            "timestamp": timestamp,
            "sign": self._auth_sign(path, timestamp),
        }
        body = {
            "refresh_token": self.refresh_token,
            "shop_id": self.shop_id,
            "partner_id": self.partner_id,
        }
        try:
            resp = self._session.post(f"{self.base_url}{path}", params=params, json=body, timeout=30)
            resp.raise_for_status()
            data = resp.json()
            response = data.get("response") or data
            new_access_token = response.get("access_token")
            new_refresh_token = response.get("refresh_token")
            if new_access_token:
                self.access_token = new_access_token
            if new_refresh_token:
                self.refresh_token = new_refresh_token
        except Exception:
            # Keep the configured token if refresh fails.
            pass

    def _send_with_refresh(
        self,
        method: str,
        path: str,
        *,
        extra_params: dict | None = None,
        json_body: dict | None = None,
        form_data: dict | None = None,
        files: dict | None = None,
        timeout: int = 30,
    ) -> requests.Response:
        params = {**self._base_params(path), **(extra_params or {})}
        headers = {"Content-Type": "application/json"} if files is None else None
        resp = self._session.request(
            method,
            f"{self.base_url}{path}",
            params=params,
            json=json_body,
            data=form_data,
            files=files,
            headers=headers,
            timeout=timeout,
        )
        if resp.status_code == 403 and self.refresh_token and self._is_invalid_token_response(resp):
            self._refresh_access_token()
            params = {**self._base_params(path), **(extra_params or {})}
            resp = self._session.request(
                method,
                f"{self.base_url}{path}",
                params=params,
                json=json_body,
                data=form_data,
                files=files,
                headers=headers,
                timeout=timeout,
            )
        return resp

    @retry(
        retry=retry_if_exception(_is_retryable),
        wait=wait_exponential(multiplier=1, min=2, max=30),
        stop=stop_after_attempt(4),
    )
    def _get(self, path: str, extra_params: dict | None = None) -> dict:
        resp = self._send_with_refresh("GET", path, extra_params=extra_params, timeout=30)
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise requests.HTTPError(f"{exc} | body={resp.text[:400]}", response=resp) from exc
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
        resp = self._send_with_refresh("POST", path, extra_params=extra_params, json_body=body, timeout=30)
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise requests.HTTPError(f"{exc} | body={resp.text[:400]}", response=resp) from exc
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
        return self.upload_image_full(local_path)["image_id"]

    def upload_image_full(self, local_path: str) -> dict:
        """Faz upload de imagem local e retorna image_id e, se disponivel, a URL Shopee."""
        path = "/media_space/upload_image"
        with open(local_path, "rb") as f:
            resp = self._send_with_refresh(
                "POST",
                path,
                form_data={"scene": "normal"},
                files={"image": (Path(local_path).name, f, "image/jpeg")},
                timeout=60,
            )
        resp.raise_for_status()
        data = resp.json()
        if data.get("error"):
            raise RuntimeError(f"Shopee API error {data['error']}: {data.get('message')}")
        response = data.get("response") or data
        image_info = response.get("image_info") or {}
        image_info_list = response.get("image_info_list") or []
        if not image_info and isinstance(image_info_list, list) and image_info_list:
            first_info = image_info_list[0] or {}
            if isinstance(first_info, dict):
                image_info = first_info
        image_id = str(response.get("image_id") or image_info.get("image_id") or "")
        image_url = ""
        image_url_list = response.get("image_url_list") or image_info.get("image_url_list") or []
        if isinstance(image_url_list, list) and image_url_list:
            first = image_url_list[0] or {}
            if isinstance(first, dict):
                image_url = str(first.get("image_url") or "")
            elif isinstance(first, str):
                image_url = first
        if not image_url:
            image_url = str(response.get("image_url") or image_info.get("image_url") or "")
        if not image_id:
            raise RuntimeError(f"Shopee upload_image did not return image_id: {str(response)[:500]}")
        return {
            "image_id": image_id,
            "image_url": image_url,
            "raw": response,
        }

    def upload_image_by_url(self, url: str) -> str:
        """Faz upload de imagem via URL para Shopee e retorna image_id."""
        path = "/media_space/upload_image_by_url"
        data = self._post(path, {"url": url})
        return data["response"]["image_id"]
