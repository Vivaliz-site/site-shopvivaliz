"""Cliente Shopee Open API v2 para Media Space e operações de produto."""
from __future__ import annotations

import hashlib
import hmac
import os
import time
from pathlib import Path
from typing import Any

import requests
from tenacity import retry, stop_after_attempt, wait_exponential

_HOST = "https://partner.shopeemobile.com"
_API  = "/api/v2"


class ShopeeClient:
    """Encapsula autenticação e chamadas à Shopee Open API v2."""

    def __init__(self) -> None:
        self._partner_id  = os.environ.get("SHOPEE_PARTNER_ID", "").strip()
        self._partner_key = os.environ.get("SHOPEE_PARTNER_KEY", "").strip()
        self._access_token = os.environ.get("SHOPEE_ACCESS_TOKEN", "").strip()
        self._shop_id = os.environ.get("SHOPEE_SHOP_ID", "").strip()
        self._base_url = os.environ.get("SHOPEE_BASE_URL", _HOST + _API).rstrip("/")

        if not all([self._partner_id, self._partner_key, self._access_token, self._shop_id]):
            raise RuntimeError(
                "Credenciais Shopee ausentes. Configure as env vars: "
                "SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY, SHOPEE_ACCESS_TOKEN, SHOPEE_SHOP_ID"
            )

    # ── Assinatura ─────────────────────────────────────────────

    def _sign(self, path: str, ts: int) -> str:
        """HMAC-SHA256 autenticado (partner_id + path + ts + access_token + shop_id)."""
        base = f"{self._partner_id}{path}{ts}{self._access_token}{self._shop_id}"
        return hmac.new(
            self._partner_key.encode("utf-8"),
            base.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

    def _params(self, path: str) -> dict[str, Any]:
        ts = int(time.time())
        return {
            "partner_id":   int(self._partner_id),
            "timestamp":    ts,
            "access_token": self._access_token,
            "shop_id":      int(self._shop_id),
            "sign":         self._sign(path, ts),
        }

    # ── Upload de imagem ───────────────────────────────────────

    @retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=2, max=10))
    def upload_image_full(self, local_path: str) -> dict[str, Any]:
        """Faz upload de uma imagem e retorna {image_id, image_url}."""
        path = "/api/v2/media_space/upload_image"
        p = self._params(path)
        file = Path(local_path)
        mime = "image/jpeg" if file.suffix.lower() in (".jpg", ".jpeg") else "image/png"

        resp = requests.post(
            _HOST + path,
            params=p,
            files={"image": (file.name, file.read_bytes(), mime)},
            timeout=40,
        )
        data = resp.json()
        if resp.status_code != 200 or data.get("error") not in ("", None, 0):
            raise RuntimeError(f"upload_image falhou: {data.get('message')} | {data}")

        info = data["response"]["image_info"]
        url_list = info.get("image_url_list") or []
        image_url = url_list[0]["image_url"] if url_list else ""
        return {
            "image_id":  info.get("image_id", ""),
            "image_url": image_url,
        }

    # ── Detalhes de produto ────────────────────────────────────

    @retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=2, max=10))
    def get_product_details(self, item_ids: list[int]) -> list[dict[str, Any]]:
        """Busca detalhes de até 50 produtos de uma vez."""
        if not item_ids:
            return []
        path = "/api/v2/product/get_item_base_info"
        p = self._params(path)
        p["item_id_list"] = ",".join(str(i) for i in item_ids[:50])
        p["need_complaint_policy"] = False

        resp = requests.get(_HOST + path, params=p, timeout=30)
        data = resp.json()
        if resp.status_code != 200 or data.get("error") not in ("", None, 0):
            raise RuntimeError(f"get_item_base_info falhou: {data.get('message')} | {data}")

        return data.get("response", {}).get("item_list") or []

    # ── Atualização de produto ─────────────────────────────────

    @retry(stop=stop_after_attempt(3), wait=wait_exponential(multiplier=1, min=2, max=10))
    def update_product(self, item_id: int, image_ids: list[str]) -> dict[str, Any]:
        """Atualiza a lista de imagens de um produto (capa = primeira)."""
        path = "/api/v2/product/update_item"
        p = self._params(path)
        body: dict[str, Any] = {
            "item_id": item_id,
            "image": {
                "image_id_list": image_ids,
            },
        }
        resp = requests.post(_HOST + path, params=p, json=body, timeout=30)
        data = resp.json()
        if resp.status_code != 200 or data.get("error") not in ("", None, 0):
            raise RuntimeError(f"update_item falhou: {data.get('message')} | {data}")
        return data.get("response", {})
