#!/usr/bin/env python3
"""Cliente real do TikTok Shop Products API, sem modo simulado.

Atualiza somente textos e imagens. Preço, estoque, inventário e quantidade são
bloqueados estruturalmente.
"""
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
        self.app_key = (os.environ.get("TIKTOK_APP_KEY") or os.environ.get("TIKTOK_CLIENT_ID") or "").strip()
        self.app_secret = (os.environ.get("TIKTOK_APP_SECRET") or os.environ.get("TIKTOK_CLIENT_SECRET") or "").strip()
        self.access_token = (os.environ.get("TIKTOK_ACCESS_TOKEN") or "").strip()
        self.refresh_token = (os.environ.get("TIKTOK_REFRESH_TOKEN") or "").strip()
        self.shop_cipher = (os.environ.get("TIKTOK_SHOP_CIPHER") or os.environ.get("TIKTOK_SHOP_ID") or "").strip()
        self.base_url = os.environ.get("TIKTOK_SHOP_API_ENDPOINT", "https://open-api.tiktokglobalshop.com").rstrip("/")
        self.token_file = Path(os.environ.get("TIKTOK_TOKEN_FILE", "storage/private/tiktok-tokens.json"))
        self.access_token_expire_in = 0
        self._session = requests.Session()
        self._load_token_cache()
        missing = [
            name
            for name, value in {
                "TIKTOK_APP_KEY": self.app_key,
                "TIKTOK_APP_SECRET": self.app_secret,
                "TIKTOK_SHOP_CIPHER": self.shop_cipher,
            }.items()
            if not value
        ]
        if missing or (not self.access_token and not self.refresh_token):
            if not self.access_token and not self.refresh_token:
                missing.append("TIKTOK_ACCESS_TOKEN ou TIKTOK_REFRESH_TOKEN")
            raise RuntimeError("Credenciais TikTok Shop ausentes: " + ", ".join(missing))
        if not self.access_token or (self.access_token_expire_in and self.access_token_expire_in <= int(time.time()) + 600):
            self._refresh_access_token()

    def _load_token_cache(self) -> None:
        if not self.token_file.is_file():
            return
        try:
            data = json.loads(self.token_file.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return
        self.access_token = str(data.get("access_token") or self.access_token).strip()
        self.refresh_token = str(data.get("refresh_token") or self.refresh_token).strip()
        self.access_token_expire_in = int(data.get("access_token_expire_in") or 0)

    def _save_token_cache(self, data: dict[str, Any]) -> None:
        self.token_file.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
        temporary = self.token_file.with_name(f".{self.token_file.name}.{os.getpid()}.tmp")
        original = self.token_file.stat() if self.token_file.exists() else None
        try:
            temporary.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            temporary.chmod((original.st_mode & 0o777) if original else 0o640)
            if original and hasattr(os, "chown"):
                os.chown(temporary, original.st_uid, original.st_gid)
            os.replace(temporary, self.token_file)
        finally:
            temporary.unlink(missing_ok=True)

    def _refresh_access_token(self) -> None:
        if not self.refresh_token:
            raise RuntimeError("TIKTOK_REFRESH_TOKEN ausente; reautorize a loja")
        response = self._session.get(
            "https://auth.tiktok-shops.com/api/v2/token/refresh",
            params={
                "app_key": self.app_key,
                "app_secret": self.app_secret,
                "refresh_token": self.refresh_token,
                "grant_type": "refresh_token",
            },
            timeout=30,
        )
        response.raise_for_status()
        payload = response.json()
        if int(payload.get("code", -1)) != 0:
            raise RuntimeError(f"TikTok token refresh {payload.get('code')}: {payload.get('message')}")
        data = payload.get("data") or {}
        access_token = str(data.get("access_token") or "").strip()
        if not access_token:
            raise RuntimeError("TikTok token refresh não retornou access_token")
        self.access_token = access_token
        self.refresh_token = str(data.get("refresh_token") or self.refresh_token).strip()
        self.access_token_expire_in = int(data.get("access_token_expire_in") or 0)
        self._save_token_cache(
            {
                "access_token": self.access_token,
                "refresh_token": self.refresh_token,
                "access_token_expire_in": self.access_token_expire_in,
                "refresh_token_expire_in": int(data.get("refresh_token_expire_in") or 0),
                "updated_at": int(time.time()),
            }
        )

    def _sign(self, path: str, params: dict[str, Any], body: str = "") -> str:
        clean = {key: value for key, value in params.items() if key not in {"sign", "access_token"}}
        parameter_string = "".join(f"{key}{clean[key]}" for key in sorted(clean))
        base = f"{self.app_secret}{path}{parameter_string}{body}{self.app_secret}"
        return hmac.new(self.app_secret.encode(), base.encode(), hashlib.sha256).hexdigest()

    @staticmethod
    def _token_failure(response: requests.Response, payload: dict[str, Any] | None = None) -> bool:
        if response.status_code in {401, 403}:
            return True
        text = (response.text + " " + json.dumps(payload or {})).lower()
        return "access_token" in text or "access token" in text or "token expired" in text

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
        allow_refresh: bool = True,
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
        response = self._session.request(
            method,
            f"{self.base_url}{path}?{urlencode(params)}",
            headers=headers,
            data=body_text.encode("utf-8") if body is not None else form,
            files=files,
            timeout=60,
        )
        payload: dict[str, Any] | None = None
        try:
            payload = response.json()
        except ValueError:
            payload = None
        if allow_refresh and self.refresh_token and self._token_failure(response, payload):
            self._refresh_access_token()
            return self._request(
                method,
                path,
                body=body,
                query=query,
                include_shop_cipher=include_shop_cipher,
                files=files,
                form=form,
                allow_refresh=False,
            )
        response.raise_for_status()
        if payload is None:
            raise RuntimeError(f"TikTok Shop retornou JSON inválido: {response.text[:500]}")
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
            yield from data.get("products") or []
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

    def get_product(self, product_id: str, *, under_review: bool = False) -> dict[str, Any]:
        query = {"return_under_review_version": "true"} if under_review else None
        payload = self._request("GET", f"/product/202309/products/{product_id}", query=query)
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
            body["title"] = title[:300]
        if description is not None:
            body["description"] = description[:10000]
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
        result = self._request("POST", f"/product/202509/products/{product_id}/partial_edit", body=body)
        review = self.get_product(product_id, under_review=True)
        if title is not None and str(review.get("title") or "") != body["title"]:
            live = self.get_product(product_id)
            if str(live.get("title") or "") != body["title"]:
                raise RuntimeError("TikTok não confirmou o título no read-back")
        return result
