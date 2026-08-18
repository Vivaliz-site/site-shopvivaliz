"""Shared Google OAuth refresh helper for ShopVivaliz automation.

Never hardcode or log OAuth credentials/tokens. The helper reads only:
GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET, GOOGLE_OAUTH_REFRESH_TOKEN.
"""

from __future__ import annotations

import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Optional


TOKEN_ENDPOINT = "https://oauth2.googleapis.com/token"
EXPIRY_SKEW_SECONDS = 120


class GoogleOAuthError(RuntimeError):
    """Sanitized Google OAuth error that never contains credentials or tokens."""


@dataclass
class GoogleOAuthTokenProvider:
    client_id: str
    client_secret: str
    refresh_token: str
    _access_token: Optional[str] = None
    _expires_at: float = 0.0

    @classmethod
    def from_environment(cls) -> "GoogleOAuthTokenProvider":
        values = {
            "client_id": os.environ.get("GOOGLE_OAUTH_CLIENT_ID", "").strip(),
            "client_secret": os.environ.get("GOOGLE_OAUTH_CLIENT_SECRET", "").strip(),
            "refresh_token": os.environ.get("GOOGLE_OAUTH_REFRESH_TOKEN", "").strip(),
        }
        missing = [key for key, value in values.items() if not value]
        if missing:
            raise GoogleOAuthError(
                "Google OAuth is not configured. Required env vars: "
                "GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET, "
                "GOOGLE_OAUTH_REFRESH_TOKEN."
            )
        return cls(**values)

    def get_access_token(self, force_refresh: bool = False) -> str:
        now = time.time()
        if (
            not force_refresh
            and self._access_token
            and self._expires_at > now + EXPIRY_SKEW_SECONDS
        ):
            return self._access_token

        payload = urllib.parse.urlencode(
            {
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "refresh_token": self.refresh_token,
                "grant_type": "refresh_token",
            }
        ).encode("utf-8")
        request = urllib.request.Request(
            TOKEN_ENDPOINT,
            data=payload,
            method="POST",
            headers={
                "Accept": "application/json",
                "Content-Type": "application/x-www-form-urlencoded",
                "User-Agent": "ShopVivaliz-GoogleOAuth/1.0",
            },
        )

        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                status = int(response.status)
                body = response.read().decode("utf-8")
        except urllib.error.HTTPError as exc:
            # Do not include the raw response body: keep errors free of token data.
            raise GoogleOAuthError(
                f"Google OAuth token refresh failed with HTTP {exc.code}."
            ) from None
        except urllib.error.URLError as exc:
            reason = getattr(exc, "reason", "transport error")
            raise GoogleOAuthError(f"Google OAuth transport error: {reason}.") from None

        if status < 200 or status >= 300:
            raise GoogleOAuthError(
                f"Google OAuth token refresh failed with HTTP {status}."
            )

        try:
            data = json.loads(body)
        except json.JSONDecodeError:
            raise GoogleOAuthError("Google OAuth returned invalid JSON.") from None

        token = str(data.get("access_token", "")).strip()
        if not token:
            error = str(data.get("error", "missing_access_token")).strip()
            raise GoogleOAuthError(f"Google OAuth token refresh failed: {error}.")

        expires_in = max(60, int(data.get("expires_in", 3600)))
        self._access_token = token
        self._expires_at = time.time() + expires_in
        return token
