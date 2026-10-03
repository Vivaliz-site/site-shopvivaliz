#!/usr/bin/env python3
from __future__ import annotations

import base64
import json
import os
import urllib.error
import urllib.request
from typing import NamedTuple

FROM_NAME = "ShopVivaliz"
FROM_EMAIL = "atendimento@shopvivaliz.com.br"
REPLY_TO_NAME = "ShopVivaliz Atendimento"
REPLY_TO_EMAIL = "atendimento@shopvivaliz.com.br"
BREVO_ENDPOINT = "https://api.brevo.com/v3/smtp/email"
FORBIDDEN_BRAND_MARKERS = (
    "contabilidade melo",
    "contabilidademelo",
    "fiscalmelo",
    "naoresponda@dev.shopvivaliz.com.br",
)

class MailResult(NamedTuple):
    success: bool
    message_id: str = ""
    error: str = ""
    status_code: int = 0

def _contains_forbidden(*parts: str) -> bool:
    text = "\n".join(str(part or "") for part in parts).casefold()
    return any(marker in text for marker in FORBIDDEN_BRAND_MARKERS)

def _send(
    to: str | list[str],
    subject: str,
    *,
    text: str | None = None,
    html: str | None = None,
    tags: list[str] | None = None,
    attachments: list[tuple[str, bytes]] | None = None,
) -> MailResult:
    api_key = (os.environ.get("BREVO_API_KEY") or "").strip()
    if not api_key:
        return MailResult(False, error="provider_not_configured")
    recipients = [to] if isinstance(to, str) else list(to)
    recipients = [str(item).strip() for item in recipients if str(item).strip()]
    if not recipients or any("@" not in item or "." not in item for item in recipients):
        return MailResult(False, error="invalid_recipient")
    if not subject.strip():
        return MailResult(False, error="invalid_subject")
    if (text is None) == (html is None):
        return MailResult(False, error="choose_exactly_one_body")
    if _contains_forbidden(subject, text or "", html or ""):
        return MailResult(False, error="forbidden_brand_content")

    payload = {
        "sender": {"name": FROM_NAME, "email": FROM_EMAIL},
        "replyTo": {"name": REPLY_TO_NAME, "email": REPLY_TO_EMAIL},
        "to": [{"email": item} for item in recipients],
        "subject": subject,
        "headers": {"X-ShopVivaliz-Mailer": "transactional-python-v1"},
        "tags": tags or ["shopvivaliz-transactional"],
    }
    if text is not None:
        payload["textContent"] = text
    else:
        payload["htmlContent"] = html
    if attachments:
        payload["attachment"] = [
            {"name": name, "content": base64.b64encode(content).decode("ascii")}
            for name, content in attachments
        ]

    req = urllib.request.Request(
        BREVO_ENDPOINT,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={
            "api-key": api_key,
            "accept": "application/json",
            "content-type": "application/json; charset=utf-8",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            raw = response.read().decode("utf-8", "replace")
            status = int(getattr(response, "status", 0) or 0)
        if status != 201:
            return MailResult(False, error="provider_send_failed", status_code=status)
        parsed = json.loads(raw or "{}")
        message_id = str(parsed.get("messageId") or "").strip()
        if not message_id:
            return MailResult(False, error="provider_response_invalid", status_code=status)
        return MailResult(True, message_id=message_id, status_code=status)
    except urllib.error.HTTPError as exc:
        return MailResult(False, error="provider_send_failed", status_code=int(exc.code))
    except Exception:
        return MailResult(False, error="provider_exception")

def send_text(
    to: str | list[str],
    subject: str,
    text: str,
    *,
    tags: list[str] | None = None,
    attachments: list[tuple[str, bytes]] | None = None,
) -> MailResult:
    return _send(to, subject, text=text, tags=tags, attachments=attachments)

def send_html(
    to: str | list[str],
    subject: str,
    html: str,
    *,
    tags: list[str] | None = None,
    attachments: list[tuple[str, bytes]] | None = None,
) -> MailResult:
    return _send(to, subject, html=html, tags=tags, attachments=attachments)
