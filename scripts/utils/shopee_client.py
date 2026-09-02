"""Cliente canônico Shopee Partner API v2.

Publica somente texto e imagens de produto. A renovação OAuth é preventiva,
retentada em erro de token e persistida atomicamente em arquivo privado para
não perder refresh tokens rotacionados.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import mimetypes
import os
import time
from pathlib import Path
from typing import Generator

import requests

DEFAULT_BASE_URL = "https://partner.shopeemobile.com/api/v2"
SANDBOX_BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg/api/v2"
TOKEN_REFRESH_INTERVAL_SECONDS = int(os.environ.get("SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS", "7200"))
RETRY_ATTEMPTS = 4
RETRY_BACKOFF_SECONDS = (2, 4, 8)


def _is_retryable(exc: Exception) -> bool:
    if isinstance(exc, requests.HTTPError):
        return exc.response is not None and exc.response.status_code in (429, 500, 502, 503, 504)
    return isinstance(exc, (requests.ConnectionError, requests.Timeout))


class ShopeeClient:
    def __init__(self) -> None:
        pid = os.environ.get("SHOPEE_PARTNER_ID") or os.environ.get("SHOPEE_TEST_PARTNER_ID")
        pkey = os.environ.get("SHOPEE_PARTNER_KEY") or os.environ.get("SHOPEE_TEST_PARTNER_KEY")
        if not pid or not pkey:
            raise RuntimeError("SHOPEE_PARTNER_ID/SHOPEE_PARTNER_KEY não configurados")

        self.partner_id = int(pid)
        self.partner_key = pkey.strip()
        self.access_token = (os.environ.get("SHOPEE_ACCESS_TOKEN") or "").strip()
        self.refresh_token = (os.environ.get("SHOPEE_REFRESH_TOKEN") or "").strip()
        shop_id = (os.environ.get("SHOPEE_SHOP_ID") or "").strip()
        if not shop_id:
            raise RuntimeError("SHOPEE_SHOP_ID não configurado")
        self.shop_id = int(shop_id)
        self.base_url = self._resolve_base_url()
        self.token_file = Path(os.environ.get("SHOPEE_TOKEN_FILE", "storage/private/shopee-tokens.json"))
        self.access_expires_at = 0
        self._session = requests.Session()
        self._last_refresh_attempt_monotonic = 0.0
        self._load_token_cache()

        if not self.access_token and not self.refresh_token:
            raise RuntimeError("Configure SHOPEE_ACCESS_TOKEN ou SHOPEE_REFRESH_TOKEN")
        if not self.access_token or (self.access_expires_at and self.access_expires_at <= int(time.time()) + 600):
            self._refresh_access_token(required=True)

    def _resolve_base_url(self) -> str:
        configured = (os.environ.get("SHOPEE_BASE_URL") or "").strip().rstrip("/")
        if configured:
            return configured
        if os.environ.get("SHOPEE_TEST_PARTNER_ID") or os.environ.get("SHOPEE_TEST_PARTNER_KEY"):
            return SANDBOX_BASE_URL
        return DEFAULT_BASE_URL

    def _load_token_cache(self) -> None:
        if not self.token_file.is_file():
            return
        try:
            data = json.loads(self.token_file.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return
        access = str(data.get("access_token") or "").strip()
        refresh = str(data.get("refresh_token") or "").strip()
        if access:
            self.access_token = access
        if refresh:
            self.refresh_token = refresh
        self.access_expires_at = int(data.get("expires_at") or 0)

    def _save_token_cache(self) -> None:
        self.token_file.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
        temporary = self.token_file.with_name(f".{self.token_file.name}.{os.getpid()}.tmp")
        original = self.token_file.stat() if self.token_file.exists() else None
        payload = {
            "access_token": self.access_token,
            "refresh_token": self.refresh_token,
            "expires_at": self.access_expires_at,
            "updated_at": int(time.time()),
        }
        try:
            temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            temporary.chmod((original.st_mode & 0o777) if original else 0o640)
            if original and hasattr(os, "chown"):
                os.chown(temporary, original.st_uid, original.st_gid)
            os.replace(temporary, self.token_file)
        finally:
            temporary.unlink(missing_ok=True)

    @staticmethod
    def _signed_path(path: str) -> str:
        return path if path.startswith("/api/") else f"/api/v2{path}"

    def _sign(self, path: str, timestamp: int) -> str:
        api_path = self._signed_path(path)
        base = f"{self.partner_id}{api_path}{timestamp}{self.access_token}{self.shop_id}"
        return hmac.new(self.partner_key.encode(), base.encode(), hashlib.sha256).hexdigest()

    def _auth_sign(self, path: str, timestamp: int) -> str:
        api_path = self._signed_path(path)
        base = f"{self.partner_id}{api_path}{timestamp}"
        return hmac.new(self.partner_key.encode(), base.encode(), hashlib.sha256).hexdigest()

    def _base_params(self, path: str) -> dict:
        if not self.access_token:
            raise RuntimeError("Shopee access token indisponível")
        timestamp = int(time.time())
        return {
            "partner_id": self.partner_id,
            "timestamp": timestamp,
            "access_token": self.access_token,
            "shop_id": self.shop_id,
            "sign": self._sign(path, timestamp),
        }

    @staticmethod
    def _invalid_token_response(resp: requests.Response) -> bool:
        if resp.status_code in (401, 403):
            return True
        try:
            data = resp.json()
        except ValueError:
            return False
        text = (str(data.get("error", "")) + " " + str(data.get("message", ""))).lower()
        return "access_token" in text or "invalid token" in text or "token expired" in text

    def _refresh_if_due(self) -> None:
        if not self.refresh_token:
            return
        now = time.monotonic()
        time_due = self._last_refresh_attempt_monotonic == 0.0 or now - self._last_refresh_attempt_monotonic >= TOKEN_REFRESH_INTERVAL_SECONDS
        expiry_due = self.access_expires_at > 0 and self.access_expires_at <= int(time.time()) + 600
        if time_due or expiry_due:
            self._refresh_access_token(required=not bool(self.access_token) or expiry_due)

    def _refresh_access_token(self, *, required: bool = False) -> None:
        if not self.refresh_token:
            if required:
                raise RuntimeError("SHOPEE_REFRESH_TOKEN não configurado")
            return
        self._last_refresh_attempt_monotonic = time.monotonic()
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
            if data.get("error"):
                raise RuntimeError(f"Shopee token refresh {data['error']}: {data.get('message')}")
            response = data.get("response") or data
            new_access = str(response.get("access_token") or "").strip()
            new_refresh = str(response.get("refresh_token") or "").strip()
            if not new_access:
                raise RuntimeError("Shopee token refresh não retornou access_token")
            self.access_token = new_access
            if new_refresh:
                self.refresh_token = new_refresh
            expire_in = max(0, int(response.get("expire_in") or 0))
            self.access_expires_at = int(time.time()) + expire_in if expire_in else 0
            self._save_token_cache()
        except Exception as exc:
            if required:
                raise RuntimeError(f"Falha ao renovar token Shopee: {exc}") from exc

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
        self._refresh_if_due()
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
        if self.refresh_token and self._invalid_token_response(resp):
            self._refresh_access_token(required=True)
            if files:
                for value in files.values():
                    file_object = value[1] if isinstance(value, tuple) and len(value) > 1 else value
                    if hasattr(file_object, "seek"):
                        file_object.seek(0)
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

    @staticmethod
    def _decode(resp: requests.Response) -> dict:
        try:
            resp.raise_for_status()
        except requests.HTTPError as exc:
            raise requests.HTTPError(f"{exc} | body={resp.text[:500]}", response=resp) from exc
        data = resp.json()
        if data.get("error"):
            raise RuntimeError(f"Shopee API error {data['error']}: {data.get('message')}")
        return data

    @staticmethod
    def _call_with_retry(operation):
        for attempt in range(RETRY_ATTEMPTS):
            try:
                return operation()
            except Exception as exc:
                if not _is_retryable(exc) or attempt == RETRY_ATTEMPTS - 1:
                    raise
                time.sleep(RETRY_BACKOFF_SECONDS[attempt])
        raise RuntimeError("unreachable retry state")

    def _get(self, path: str, extra_params: dict | None = None) -> dict:
        return self._call_with_retry(
            lambda: self._decode(self._send_with_refresh("GET", path, extra_params=extra_params, timeout=30))
        )

    def _post(self, path: str, body: dict, extra_params: dict | None = None) -> dict:
        return self._call_with_retry(
            lambda: self._decode(
                self._send_with_refresh("POST", path, extra_params=extra_params, json_body=body, timeout=30)
            )
        )

    def iter_all_products(self, page_size: int = 100) -> Generator[dict, None, None]:
        path = "/product/get_item_list"
        offset = 0
        while True:
            data = self._get(path, {"offset": offset, "page_size": min(max(page_size, 1), 100), "item_status": "NORMAL"})
            response = data.get("response", {})
            items = response.get("item") or response.get("item_list") or []
            yield from items
            if not response.get("has_next_page"):
                break
            offset = int(response.get("next_offset", offset + len(items)))
            time.sleep(0.4)

    def get_product_details(self, item_ids: list[int]) -> list[dict]:
        results: list[dict] = []
        for index in range(0, len(item_ids), 50):
            batch = item_ids[index : index + 50]
            data = self._get("/product/get_item_base_info", {"item_id_list": ",".join(str(value) for value in batch)})
            results.extend(data.get("response", {}).get("item_list", []))
            time.sleep(0.3)
        return results

    def update_product(
        self,
        item_id: int,
        *,
        title: str | None = None,
        description: str | None = None,
        image_ids: list[str] | None = None,
        attribute_list: list[dict] | None = None,
    ) -> dict:
        body: dict = {"item_id": item_id}
        if title is not None:
            body["item_name"] = title[:120]
        if description is not None:
            body["description"] = description
        if image_ids is not None:
            body["image"] = {"image_id_list": image_ids[:9]}
        if attribute_list is not None:
            body["attribute_list"] = attribute_list
        if len(body) == 1:
            raise ValueError("Nenhum campo informado para atualizar o produto")
        forbidden = {"price", "stock", "inventory", "normal_stock", "seller_stock"}
        if forbidden.intersection(body):
            raise ValueError("Preço/estoque são proibidos nesta rotina")
        return self._post("/product/update_item", body)

    def upload_image(self, local_path: str) -> str:
        return self.upload_image_full(local_path)["image_id"]

    def upload_image_full(self, local_path: str) -> dict:
        path = "/media_space/upload_image"
        file_path = Path(local_path)
        if not file_path.is_file():
            raise FileNotFoundError(file_path)
        mime = mimetypes.guess_type(file_path.name)[0] or "image/jpeg"
        with file_path.open("rb") as handle:
            resp = self._send_with_refresh(
                "POST",
                path,
                form_data={"scene": "normal"},
                files={"image": (file_path.name, handle, mime)},
                timeout=60,
            )
        data = self._decode(resp)
        response = data.get("response") or data
        image_info = response.get("image_info") or {}
        image_info_list = response.get("image_info_list") or []
        if not image_info and isinstance(image_info_list, list) and image_info_list:
            image_info = image_info_list[0] or {}
        image_id = str(response.get("image_id") or image_info.get("image_id") or "")
        image_url_list = response.get("image_url_list") or image_info.get("image_url_list") or []
        image_url = ""
        if isinstance(image_url_list, list) and image_url_list:
            first = image_url_list[0]
            image_url = str(first.get("image_url") or "") if isinstance(first, dict) else str(first)
        if not image_url:
            image_url = str(response.get("image_url") or image_info.get("image_url") or "")
        if not image_id:
            raise RuntimeError(f"Shopee upload_image não retornou image_id: {str(response)[:500]}")
        return {"image_id": image_id, "image_url": image_url, "raw": response}
