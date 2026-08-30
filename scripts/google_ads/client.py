from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any


REQUIRED_KEYS = (
    "GOOGLE_OAUTH_CLIENT_ID",
    "GOOGLE_OAUTH_CLIENT_SECRET",
    "GOOGLE_ADS_REFRESH_TOKEN",
    "GOOGLE_ADS_DEVELOPER_TOKEN",
    "GOOGLE_ADS_CUSTOMER_ID",
)


def load_env(path: Path) -> dict[str, str]:
    """Load a simple dotenv file without exporting or displaying its values."""
    env: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip("\"'")
    return env


@dataclass(frozen=True)
class GoogleAdsError(RuntimeError):
    label: str
    status: str
    message: str
    reasons: tuple[str, ...] = ()

    def __str__(self) -> str:
        reason_text = ", ".join(self.reasons)
        result = f"{self.label}: {self.status}: {self.message}"
        return result + (f" ({reason_text})" if reason_text else "")


def _api_error(error: urllib.error.HTTPError, label: str) -> GoogleAdsError:
    body = error.read().decode(errors="ignore")
    try:
        payload = json.loads(body)
    except (TypeError, ValueError):
        payload = {}
    if isinstance(payload, list) and payload and isinstance(payload[0], dict):
        payload = payload[0]
    api_error = payload.get("error", {}) if isinstance(payload, dict) else {}
    status = str(api_error.get("status") or f"HTTP_{error.code}")
    message = str(api_error.get("message") or "Google Ads API request failed")[:700]
    reasons: list[str] = []
    for detail in api_error.get("details") or []:
        if not isinstance(detail, dict):
            continue
        for item in detail.get("errors") or []:
            if not isinstance(item, dict):
                continue
            for key, value in (item.get("errorCode") or {}).items():
                reasons.append(f"{key}={value}")
    return GoogleAdsError(label, status, message, tuple(dict.fromkeys(reasons)))


class GoogleAdsClient:
    """Minimal Google Ads searchStream client with no mutation surface."""

    def __init__(self, env: dict[str, str], api_version: str = "v25") -> None:
        self._env = dict(env)
        self.api_version = api_version
        self.customer_id = env["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
        self.login_customer_id = env.get("GOOGLE_ADS_LOGIN_CUSTOMER_ID", "").replace("-", "").strip()
        self._access_token: str | None = None

    @classmethod
    def from_env(
        cls,
        env: dict[str, str],
        api_version: str = "v25",
    ) -> "GoogleAdsClient":
        missing = [key for key in REQUIRED_KEYS if not env.get(key, "").strip()]
        if missing:
            raise ValueError("missing Google Ads environment keys: " + ",".join(missing))
        return cls(env, api_version)

    def _refresh_access_token(self) -> str:
        data = urllib.parse.urlencode(
            {
                "client_id": self._env["GOOGLE_OAUTH_CLIENT_ID"],
                "client_secret": self._env["GOOGLE_OAUTH_CLIENT_SECRET"],
                "refresh_token": self._env["GOOGLE_ADS_REFRESH_TOKEN"],
                "grant_type": "refresh_token",
            }
        ).encode()
        request = urllib.request.Request(
            "https://oauth2.googleapis.com/token",
            data=data,
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                payload: Any = json.loads(response.read().decode())
        except urllib.error.HTTPError as error:
            raise _api_error(error, "oauth_refresh") from None
        token = payload.get("access_token", "") if isinstance(payload, dict) else ""
        if not token:
            raise GoogleAdsError("oauth_refresh", "INVALID_RESPONSE", "access token missing")
        self._access_token = str(token)
        return self._access_token

    def query(self, label: str, gaql: str) -> list[dict[str, Any]]:
        token = self._access_token or self._refresh_access_token()
        headers = {
            "Content-Type": "application/json",
            "Authorization": "Bearer " + token,
            "developer-token": self._env["GOOGLE_ADS_DEVELOPER_TOKEN"],
        }
        if self.login_customer_id:
            headers["login-customer-id"] = self.login_customer_id
        url = (
            f"https://googleads.googleapis.com/{self.api_version}/customers/"
            f"{self.customer_id}/googleAds:searchStream"
        )
        request = urllib.request.Request(
            url,
            data=json.dumps({"query": gaql}).encode(),
            headers=headers,
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                payload = json.loads(response.read().decode())
        except urllib.error.HTTPError as error:
            raise _api_error(error, label) from None

        rows: list[dict[str, Any]] = []
        for batch in payload if isinstance(payload, list) else []:
            if isinstance(batch, dict):
                rows.extend(row for row in (batch.get("results") or []) if isinstance(row, dict))
        return rows
