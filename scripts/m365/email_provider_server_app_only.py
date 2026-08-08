"""ShopVivaliz e-mail provider.

Production delivery is intentionally restricted to Microsoft Graph app-only
using the certificate/key installed on the Oracle VM. SMTP, Gmail, Brevo,
delegated device login, refresh-token caches and client secrets are not
supported by this provider.
"""
from __future__ import annotations

import base64
import hashlib
import json
import logging
import os
import subprocess
import time
import uuid
from abc import ABC, abstractmethod
from dataclasses import dataclass
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

logger = logging.getLogger("mei_mg_email.email_provider")


@dataclass
class SendResult:
    success: bool
    message_id: str | None = None
    error: str | None = None


class EmailProvider(ABC):
    @abstractmethod
    def send(self, to: str, subject: str, body: str) -> SendResult:
        raise NotImplementedError


class DryRunEmailProvider(EmailProvider):
    def send(self, to: str, subject: str, body: str) -> SendResult:
        fake_id = f"dryrun-{int(time.time() * 1000)}"
        logger.info("DRY-RUN to=%s subject=%r body_chars=%d id=%s", to, subject, len(body), fake_id)
        return SendResult(success=True, message_id=fake_id)


def _body_is_html(body: str) -> bool:
    value = body.strip().lower()
    return value.startswith("<!doctype html") or value.startswith("<html") or "<body" in value


def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


class MicrosoftGraphEmailProvider(EmailProvider):
    """Microsoft Graph application authentication using a local X.509 key."""

    def __init__(self) -> None:
        self.tenant_id = os.getenv("MICROSOFT_GRAPH_TENANT_ID", "").strip()
        self.client_id = os.getenv("MICROSOFT_GRAPH_CLIENT_ID", "").strip()
        self.address = os.getenv("MICROSOFT_GRAPH_USER", "").strip()
        self.cert_path = Path(os.getenv("MICROSOFT_GRAPH_CERT_PATH", "/home/ubuntu/.shopvivaliz/m365/graph-auth.crt"))
        self.key_path = Path(os.getenv("MICROSOFT_GRAPH_KEY_PATH", "/home/ubuntu/.shopvivaliz/m365/graph-auth.key"))
        self._token: str | None = None
        self._expires_at = 0
        missing = [name for name, value in (
            ("MICROSOFT_GRAPH_TENANT_ID", self.tenant_id),
            ("MICROSOFT_GRAPH_CLIENT_ID", self.client_id),
            ("MICROSOFT_GRAPH_USER", self.address),
        ) if not value]
        if missing:
            raise RuntimeError("Microsoft Graph app-only missing configuration: " + ", ".join(missing))
        if not self.cert_path.is_file() or not self.key_path.is_file():
            raise RuntimeError("Microsoft Graph app-only certificate/key not found on server")
        if os.getenv("MICROSOFT_GRAPH_CLIENT_SECRET", "").strip():
            raise RuntimeError("Client secret is forbidden; certificate app-only is required")
        logger.warning("MicrosoftGraphEmailProvider APP-ONLY active sender=%s cert=%s", self.address, self.cert_path)

    @property
    def _token_url(self) -> str:
        return f"https://login.microsoftonline.com/{self.tenant_id}/oauth2/v2.0/token"

    def _certificate_thumbprint_b64url(self) -> str:
        der = subprocess.check_output(["openssl", "x509", "-in", str(self.cert_path), "-outform", "DER"])
        return _b64url(hashlib.sha1(der).digest())

    def _client_assertion(self) -> str:
        now = int(time.time())
        header = _b64url(json.dumps({"alg": "RS256", "typ": "JWT", "x5t": self._certificate_thumbprint_b64url()}, separators=(",", ":")).encode())
        payload = _b64url(json.dumps({
            "aud": self._token_url,
            "iss": self.client_id,
            "sub": self.client_id,
            "jti": str(uuid.uuid4()),
            "nbf": now - 60,
            "exp": now + 540,
        }, separators=(",", ":")).encode())
        unsigned = f"{header}.{payload}".encode("ascii")
        signature = subprocess.check_output(["openssl", "dgst", "-sha256", "-sign", str(self.key_path)], input=unsigned)
        return unsigned.decode("ascii") + "." + _b64url(signature)

    def _get_access_token(self) -> str:
        if self._token and self._expires_at > int(time.time()) + 300:
            return self._token
        values = {
            "client_id": self.client_id,
            "scope": "https://graph.microsoft.com/.default",
            "grant_type": "client_credentials",
            "client_assertion_type": "urn:ietf:params:oauth:client-assertion-type:jwt-bearer",
            "client_assertion": self._client_assertion(),
        }
        req = Request(self._token_url, data=urlencode(values).encode(), headers={"Content-Type": "application/x-www-form-urlencoded"}, method="POST")
        try:
            with urlopen(req, timeout=30) as response:
                token = json.loads(response.read().decode())
        except HTTPError as error:
            detail = error.read().decode("utf-8", "replace")
            raise RuntimeError(f"Microsoft Graph app-only OAuth HTTP {error.code}: {detail[:400]}") from error
        self._token = token["access_token"]
        self._expires_at = int(time.time()) + int(token.get("expires_in", 3600))
        return self._token

    def authenticate(self) -> None:
        self._get_access_token()

    def send(self, to: str, subject: str, body: str) -> SendResult:
        payload = {
            "message": {
                "subject": subject,
                "body": {"contentType": "HTML" if _body_is_html(body) else "Text", "content": body},
                "toRecipients": [{"emailAddress": {"address": to}}],
            },
            "saveToSentItems": True,
        }
        try:
            req = Request(
                f"https://graph.microsoft.com/v1.0/users/{quote(self.address)}/sendMail",
                data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
                headers={"Authorization": "Bearer " + self._get_access_token(), "Content-Type": "application/json"},
                method="POST",
            )
            with urlopen(req, timeout=30) as response:
                if response.status not in (200, 202):
                    return SendResult(False, error=f"Microsoft Graph HTTP {response.status}")
            return SendResult(True)
        except HTTPError as error:
            try:
                detail = json.loads(error.read().decode("utf-8"))
                code = detail.get("error", {}).get("code", f"http_{error.code}")
            except Exception:
                code = f"http_{error.code}"
            return SendResult(False, error=f"Microsoft Graph: {code}")
        except (RuntimeError, URLError, OSError, subprocess.SubprocessError) as error:
            return SendResult(False, error=str(error))


def get_email_provider(name: str) -> EmailProvider:
    normalized = (name or "").strip().lower()
    if normalized == "dryrun":
        return DryRunEmailProvider()
    if normalized in {"microsoft_graph", "microsoft-oauth", "graph"}:
        return MicrosoftGraphEmailProvider()
    if normalized in {"gmail", "microsoft", "outlook", "office365", "brevo", "brevo_api", "smtp"}:
        raise RuntimeError(f"Email provider '{normalized}' is disabled. ShopVivaliz production is Microsoft Graph app-only only.")
    raise ValueError("Unknown e-mail provider. Use 'dryrun' or 'microsoft_graph'.")
