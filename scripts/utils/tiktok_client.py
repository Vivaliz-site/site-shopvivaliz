#!/usr/bin/env python3
"""Cliente real do TikTok Shop Products API (sem qualquer modo simulado)."""
from __future__ import annotations

import hashlib
import hmac
import json
import mimetypes
import os
import time
from pathlib import Path
from typing import Any, Iterator
from urllib.parse import urlencode

import requests


class TikTokClient:
    def __init__(self) -> None:
        self.app_key = os.environ.get("TIKTOK_APP_KEY") or os.environ.get("TIKTOK_CLIENT_ID", "")
        self.app_secret = os.environ.get("TIKTOK_APP_SECRET") or os.environ.get("TIKTOK_CLIENT_SECRET", "")
        self.access_token = os.environ.get("TIKTOK_ACCESS_TOKEN", "")
        self.shop_cipher = os.environ.get("TIKTOK_SHOP_CIPHER") or os.environ.get("TIKTOK_SHOP_ID", "")
        self.base_url = os.environ.get("TIKTOK_SHOP_API_ENDPOINT", "https://open-api.tiktokglobalshop.com").rstrip("/")
        missing = [name for name, value in {
            "TIKTOK_APP_KEY": self.app_key,
            "TIKTOK_APP_SECRET": self.app_secret,
            "TIKTOK_ACCESS_TOKEN": self.access_token,
            "TIKTOK_SHOP_CIPHER": self.shop_cipher,
        }.items() if not value]
        if missing:
            raise RuntimeError("Credenciais TikTok Shop ausentes: " + ", ".join(missing))

    def _sign(self, path: str, params: dict[str, Any], body: str = "") -> str:
        clean = {k: v for k, v in params.items() if k not in {"sign", "access_token"}}
        parameter_string = "".join(f"{key}{clean[key]}" for key in sorted(clean))
        base = f"{self.app_secret}{path}{parameter_string}{body}{self.app_secret}"
        return hmac.new(self.app_secret.encode(), base.encode(), hashlib.sha256).hexdigest()

    def _request(
        self,
        method: str,
        path: str,
        *,
        body: dict[str, Any] | None = None,
        query: dict[str, Any] | None = None,
        include_shop_cipher: bool = True,
        files: dict[str, Any] | None = None,
        form: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        params: dict[str, Any] = {"app_key": self.app_key, "timestamp": int(time.time())}
        if include_shop_cipher:
            params["shop_cipher"] = self.shop_cipher
        if query:
            params.update(query)
        body_text = "" if body is None else json.dumps(body, ensure_ascii=False, separators=(",", ":"))
        params["sign"] = self._sign(path, params, body_text)
        headers = {"x-tts-access-token": self.access_token, "Accept": "application/json"}
        if files is None:
            headers["Content-Type"] = "application/json"
        response = requests.request(
            method,
            f"{self.base_url}{path}?{urlencode(params)}",
            headers=headers,
            data=body_text.encode("utf-8") if body is not None else form,
            files=files,
            timeout=60,
        )
        response.raise_for_status()
        payload = response.json()
        code = int(payload.get("code", 0) or 0)
        if code not in {0, 200}:
            raise RuntimeError(f"TikTok Shop {code}: {payload.get('message', 'erro desconhecido')}")
        return payload

    def iter_all_products(self, page_size: int = 100) -> Iterator[dict[str, Any]]:
        page_token = ""
        while True:
            query: dict[str, Any] = {"page_size": max(1, min(page_size, 100))}
            if page_token:
                query["page_token"] = page_token
            payload = self._request("POST", "/product/202309/products/search", body={}, query=query)
            data = payload.get("data") or {}
            for product in data.get("products") or []:
                yield product
            page_token = str(data.get("next_page_token") or "")
            if not page_token:
                break

    def find_product_by_sku(self, seller_sku: str) -> dict[str, Any]:
        payload = self._request(
            "POST",
            "/product/202309/products/search",
            body={"seller_skus": [seller_sku]},
            query={"page_size": 100},
        )
        products = (payload.get("data") or {}).get("products") or []
        for product in products:
            for sku in product.get("skus") or []:
                if str(sku.get("seller_sku") or "") == seller_sku:
                    return product
        if len(products) == 1:
            return products[0]
        raise RuntimeError(f"Produto TikTok não localizado para SKU {seller_sku}")

    def get_product(self, product_id: str) -> dict[str, Any]:
        payload = self._request("GET", f"/product/202309/products/{product_id}")
        return payload.get("data") or {}

    def upload_image(self, file_path: str | Path) -> dict[str, Any]:
        path = Path(file_path)
        if not path.is_file():
            raise FileNotFoundError(path)
        mime = mimetypes.guess_type(path.name)[0] or "image/png"
        with path.open("rb") as handle:
            payload = self._request(
                "POST",
                "/product/202309/images/upload",
                include_shop_cipher=False,
                files={"data": (path.name, handle, mime)},
                form={"use_case": "MAIN_IMAGE"},
            )
        data = payload.get("data") or {}
        if not data.get("uri"):
            raise RuntimeError("TikTok Shop não retornou URI de imagem")
        return data

    def update_product(
        self,
        product_id: str,
        *,
        title: str | None = None,
        description: str | None = None,
        image_urls: list[str] | None = None,
        image_files: list[str | Path] | None = None,
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"save_mode": "LISTING"}
        if title is not None:
            body["title"] = title
        if description is not None:
            body["description"] = description
        if image_files:
            new_uris = [self.upload_image(path)["uri"] for path in image_files]
            current = self.get_product(product_id)
            old_uris = [str(item.get("uri")) for item in current.get("main_images") or [] if item.get("uri")]
            body["main_images"] = [{"uri": uri} for uri in list(dict.fromkeys(new_uris + old_uris))[:9]]
        elif image_urls:
            raise RuntimeError("TikTok Shop exige upload local pela API; URLs externas não podem ser usadas diretamente")
        if len(body) == 1:
            raise ValueError("Nenhum campo de produto informado")
        forbidden = {"price", "inventory", "stock", "quantity"}
        if forbidden.intersection(body):
            raise ValueError("Preço/estoque são proibidos nesta rotina")
        return self._request("POST", f"/product/202509/products/{product_id}/partial_edit", body=body)
