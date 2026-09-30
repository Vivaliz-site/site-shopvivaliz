#!/usr/bin/env python3
"""Arrange Shopee shipments safely and prepare Turbo shipping labels.

The worker is deterministic and intended for a short systemd timer cycle.  It
only considers seller-fulfilled packages that Shopee itself exposes as ready to
process and with invoice_pending=false.  Dry-run is the default; production
systemd explicitly passes --apply.
"""
from __future__ import annotations

import argparse
import base64
import json
import os
import smtplib
import sys
import time
import urllib.request
from datetime import datetime, timezone
from email.message import EmailMessage
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from utils.shopee_client import ShopeeClient  # noqa: E402

TURBO_CHANNELS = {90011, 90012, 90026}
LABEL_ALERT_CHANNELS = {90011, 90012}
DEFAULT_STANDARD_DELAY_SECONDS = 3600
DEFAULT_SHARED_ROOT = Path("/home/ubuntu/shopvivaliz-deploy/shared")


class ManualActionRequired(RuntimeError):
    """Raised when Shopee requires a value the worker must not invent."""


def _truthy(name: str, default: bool = False) -> bool:
    value = (os.environ.get(name) or "").strip().lower()
    if not value:
        return default
    return value in {"1", "true", "yes", "on"}


def _clean_list(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []
    return [str(item).strip() for item in value if str(item).strip()]


def minimum_age_seconds(logistics_channel_id: int, *, standard_delay: int = DEFAULT_STANDARD_DELAY_SECONDS) -> int:
    return 0 if int(logistics_channel_id) in TURBO_CHANNELS else max(0, int(standard_delay))


def requires_label_alert(logistics_channel_id: int) -> bool:
    return int(logistics_channel_id) in LABEL_ALERT_CHANNELS


def _select_pickup_address(pickup: dict, preferred_address_id: int | None = None) -> dict:
    addresses = pickup.get("address_list") or []
    if not isinstance(addresses, list) or not addresses:
        raise ManualActionRequired("pickup address_id unavailable")
    if preferred_address_id is not None:
        candidates = [row for row in addresses if int(row.get("address_id") or 0) == int(preferred_address_id)]
        if len(candidates) != 1:
            raise ManualActionRequired("configured pickup address_id unavailable")
        return candidates[0]
    if len(addresses) == 1:
        return addresses[0]
    preferred = [
        row
        for row in addresses
        if any(str(flag).lower() in {"pickup_address", "pick_up_address"} for flag in (row.get("address_flag") or []))
    ]
    if len(preferred) == 1:
        return preferred[0]
    raise ManualActionRequired("pickup requires explicit address_id because multiple addresses are available")


def _select_pickup_time(address: dict) -> str | None:
    slots = address.get("time_slot_list") or []
    if not isinstance(slots, list) or not slots:
        return None
    recommended = [
        row for row in slots if any(str(flag).lower() == "recommended" for flag in (row.get("flags") or []))
    ]
    chosen = recommended[0] if recommended else slots[0]
    return str(chosen.get("pickup_time_id") or "").strip() or None


def build_ship_request(
    order_sn: str,
    package_number: str | None,
    shipping_parameter: dict,
    *,
    sender_real_name: str = "",
    allow_pickup: bool = False,
    pickup_address_id: int | None = None,
) -> dict:
    """Build one safe ship_order request only from Shopee-provided options."""
    body: dict[str, Any] = {"order_sn": str(order_sn)}
    if package_number:
        body["package_number"] = str(package_number)

    info = shipping_parameter.get("info_needed") or {}
    if not isinstance(info, dict):
        raise ManualActionRequired("invalid info_needed returned by Shopee")

    # Brazil OpenAPI explicitly treats an empty info_needed as dropoff with an
    # empty object.  This is also the least surprising path for the current
    # Shopee Xpress channel used by ShopVivaliz.
    if not info:
        body["dropoff"] = {}
        return body

    if "dropoff" in info:
        needed = set(_clean_list(info.get("dropoff")))
        dropoff: dict[str, Any] = {}
        details = shipping_parameter.get("dropoff") or {}
        if "branch_id" in needed:
            branches = details.get("branch_list") or []
            if not isinstance(branches, list) or len(branches) != 1:
                raise ManualActionRequired("dropoff branch_id requires exactly one Shopee branch")
            branch_id = branches[0].get("branch_id")
            if not branch_id:
                raise ManualActionRequired("dropoff branch_id missing")
            dropoff["branch_id"] = int(branch_id)
        if "sender_real_name" in needed:
            if not sender_real_name.strip():
                raise ManualActionRequired("dropoff sender_real_name is required")
            dropoff["sender_real_name"] = sender_real_name.strip()
        if "slug" in needed:
            slugs = details.get("slug_list") or []
            if not isinstance(slugs, list) or len(slugs) != 1:
                raise ManualActionRequired("dropoff slug requires exactly one Shopee option")
            slug = slugs[0].get("slug") if isinstance(slugs[0], dict) else slugs[0]
            if not slug:
                raise ManualActionRequired("dropoff slug missing")
            dropoff["slug"] = str(slug)
        tracking_fields = needed.intersection({"tracking_no", "tracking_number"})
        if tracking_fields:
            raise ManualActionRequired("dropoff tracking number must be supplied by the carrier")
        known = {"branch_id", "sender_real_name", "slug", "tracking_no", "tracking_number"}
        unknown = sorted(needed.difference(known))
        if unknown:
            raise ManualActionRequired("unsupported dropoff fields: " + ",".join(unknown))
        body["dropoff"] = dropoff
        return body

    if "pickup" in info:
        if not allow_pickup:
            raise ManualActionRequired("pickup is available but automatic pickup is disabled")
        needed = set(_clean_list(info.get("pickup")))
        if needed.intersection({"tracking_no", "tracking_number"}):
            raise ManualActionRequired("pickup tracking number must be supplied by the carrier")
        known = {"address_id", "pickup_time_id", "tracking_no", "tracking_number"}
        unknown = sorted(needed.difference(known))
        if unknown:
            raise ManualActionRequired("unsupported pickup fields: " + ",".join(unknown))
        address = _select_pickup_address(shipping_parameter.get("pickup") or {}, pickup_address_id)
        pickup: dict[str, Any] = {"address_id": int(address["address_id"])}
        if "pickup_time_id" in needed:
            pickup_time_id = _select_pickup_time(address)
            if not pickup_time_id:
                raise ManualActionRequired("pickup_time_id required but no time slot is available")
            pickup["pickup_time_id"] = pickup_time_id
        body["pickup"] = pickup
        return body

    if "non_integrated" in info or "non-integrated" in info:
        raise ManualActionRequired("non-integrated shipment requires manual carrier data")

    raise ManualActionRequired("Shopee returned no supported shipping method")


class StateStore:
    def __init__(self, shared_root: Path) -> None:
        self.root = shared_root / "storage" / "shopee-logistics-worker"
        self.root.mkdir(parents=True, exist_ok=True)
        self.state_path = self.root / "state.json"
        self.log_path = shared_root / "logs" / "shopee-logistics-worker.jsonl"
        self.log_path.parent.mkdir(parents=True, exist_ok=True)
        self.state: dict[str, Any] = {"alerts": {}, "arranged": {}, "labels": {}}
        if self.state_path.is_file():
            try:
                loaded = json.loads(self.state_path.read_text(encoding="utf-8"))
                if isinstance(loaded, dict):
                    self.state.update(loaded)
            except (OSError, json.JSONDecodeError):
                pass

    def save(self) -> None:
        temporary = self.state_path.with_name(f".{self.state_path.name}.{os.getpid()}.tmp")
        temporary.write_text(json.dumps(self.state, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        temporary.chmod(0o660)
        os.replace(temporary, self.state_path)

    def event(self, kind: str, **payload: Any) -> None:
        row = {
            "at": datetime.now(timezone.utc).isoformat(),
            "kind": kind,
            **payload,
        }
        with self.log_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")

    def recently_alerted(self, key: str, retry_seconds: int = 900) -> bool:
        value = (self.state.get("alerts") or {}).get(key)
        if isinstance(value, dict) and value.get("sent"):
            return True
        if isinstance(value, dict) and value.get("attempted_at"):
            return int(time.time()) - int(value["attempted_at"]) < retry_seconds
        return False

    def mark_alert(self, key: str, *, sent: bool) -> None:
        self.state.setdefault("alerts", {})[key] = {"attempted_at": int(time.time()), "sent": bool(sent)}
        self.save()


class AlertSender:
    def __init__(self) -> None:
        self.host = (os.environ.get("SMTP_HOST") or os.environ.get("EMAIL_SMTP_HOST") or os.environ.get("MAIL_HOST") or "").strip()
        self.port = int((os.environ.get("SMTP_PORT") or os.environ.get("EMAIL_SMTP_PORT") or os.environ.get("MAIL_PORT") or "465").strip())
        self.user = (os.environ.get("SMTP_USER") or os.environ.get("EMAIL_USER") or os.environ.get("MAIL_USER") or "").strip()
        self.password = (os.environ.get("SMTP_PASS") or os.environ.get("EMAIL_PASSWORD") or os.environ.get("MAIL_PASS") or "").strip()
        self.brevo_api_key = (os.environ.get("BREVO_API_KEY") or "").strip()
        self.from_email = (os.environ.get("EMAIL_FROM") or self.user or "").strip()
        raw_to = (os.environ.get("SHOPEE_ALERT_EMAIL_TO") or os.environ.get("EMAIL_TO") or os.environ.get("NOTIFY_EMAIL_TO") or "").strip()
        self.recipients = [part.strip() for part in raw_to.replace(";", ",").split(",") if "@" in part]

    @property
    def smtp_configured(self) -> bool:
        return bool(self.host and self.user and self.password and self.recipients)

    @property
    def brevo_configured(self) -> bool:
        return bool(self.brevo_api_key and self.from_email and self.recipients)

    @property
    def configured(self) -> bool:
        return self.brevo_configured or self.smtp_configured

    def _send_brevo(self, subject: str, body: str, attachment: Path | None = None) -> bool:
        if not self.brevo_configured:
            return False
        payload: dict[str, Any] = {
            "sender": {"email": self.from_email, "name": "ShopVivaliz"},
            "to": [{"email": recipient} for recipient in self.recipients],
            "subject": subject,
            "textContent": body,
        }
        if attachment and attachment.is_file():
            payload["attachment"] = [
                {
                    "content": base64.b64encode(attachment.read_bytes()).decode("ascii"),
                    "name": attachment.name,
                }
            ]
        request = urllib.request.Request(
            "https://api.brevo.com/v3/smtp/email",
            data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            headers={
                "api-key": self.brevo_api_key,
                "accept": "application/json",
                "content-type": "application/json",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=25) as response:
                response.read()
                return int(getattr(response, "status", 0)) in {200, 201, 202}
        except Exception:
            return False

    def _send_smtp(self, subject: str, body: str, attachment: Path | None = None) -> bool:
        if not self.smtp_configured:
            return False
        msg = EmailMessage()
        msg["From"] = self.from_email or self.user
        msg["To"] = ", ".join(self.recipients)
        msg["Subject"] = subject
        msg.set_content(body)
        if attachment and attachment.is_file():
            msg.add_attachment(
                attachment.read_bytes(),
                maintype="application",
                subtype="pdf",
                filename=attachment.name,
            )
        server: smtplib.SMTP
        try:
            if self.port == 465:
                server = smtplib.SMTP_SSL(self.host, self.port, timeout=25)
            else:
                server = smtplib.SMTP(self.host, self.port, timeout=25)
                server.ehlo()
                server.starttls()
                server.ehlo()
            try:
                server.login(self.user, self.password)
                server.send_message(msg)
            finally:
                server.quit()
            return True
        except Exception:
            return False

    def send(self, subject: str, body: str, attachment: Path | None = None) -> bool:
        # HTTPS is preferred because the production host has a validated Brevo
        # transactional key while the legacy SMTP provider currently closes the
        # connection during authentication.  SMTP stays as a fail-closed fallback.
        if self.brevo_configured and self._send_brevo(subject, body, attachment):
            return True
        if self.smtp_configured and self._send_smtp(subject, body, attachment):
            return True
        return False


def _package_key(package: dict) -> str:
    return str(package.get("package_number") or package.get("order_sn") or "").strip()


def _label_path(store: StateStore, order_sn: str, package_number: str | None) -> Path:
    stem = package_number or order_sn
    safe = "".join(ch for ch in stem if ch.isalnum() or ch in {"-", "_"})
    return store.root / "labels" / f"{safe}.pdf"


def _alert_once(store: StateStore, sender: AlertSender, key: str, subject: str, body: str, attachment: Path | None = None) -> bool:
    if store.recently_alerted(key):
        return bool((store.state.get("alerts") or {}).get(key, {}).get("sent"))
    sent = sender.send(subject, body, attachment)
    store.mark_alert(key, sent=sent)
    store.event("alert", key=key, sent=sent, subject=subject)
    return sent


def _prepare_turbo_label(client: ShopeeClient, store: StateStore, sender: AlertSender, package: dict) -> bool:
    order_sn = str(package.get("order_sn") or "").strip()
    package_number = str(package.get("package_number") or "").strip() or None
    if not order_sn:
        return False
    key = _package_key(package)
    label_file = _label_path(store, order_sn, package_number)
    label_file.parent.mkdir(parents=True, exist_ok=True)

    if not label_file.is_file():
        tracking = client.get_tracking_number(order_sn, package_number)
        if not tracking:
            _alert_once(
                store,
                sender,
                f"tracking:{key}",
                f"[SHOPEE TURBO] Tracking ainda indisponivel - {order_sn}",
                "O pedido Turbo foi organizado, mas a Shopee ainda nao retornou tracking. "
                "O worker tentara novamente automaticamente no proximo ciclo.",
            )
            return False
        parameter = client.get_shipping_document_parameter(order_sn, package_number)
        document_type = str(parameter.get("suggest_shipping_document_type") or "").strip()
        selectable = parameter.get("selectable_shipping_document_type") or []
        if not document_type and selectable:
            document_type = str(selectable[0])
        if not document_type:
            raise RuntimeError(f"shipping document type unavailable for {order_sn}")
        client.create_shipping_document(order_sn, package_number, tracking, document_type)
        result: dict[str, Any] = {}
        for _ in range(6):
            result = client.get_shipping_document_result(order_sn, package_number)
            status = str(result.get("status") or "").upper()
            if status == "READY":
                break
            if status == "FAILED":
                raise RuntimeError(
                    f"shipping document failed for {order_sn}: {result.get('fail_error')} {result.get('fail_message')}"
                )
            time.sleep(2)
        if str(result.get("status") or "").upper() != "READY":
            return False
        pdf = client.download_shipping_document(order_sn, package_number, document_type)
        temporary = label_file.with_name(f".{label_file.name}.{os.getpid()}.tmp")
        temporary.write_bytes(pdf)
        temporary.chmod(0o660)
        os.replace(temporary, label_file)
        store.state.setdefault("labels", {})[key] = {"path": str(label_file), "created_at": int(time.time())}
        store.save()
        store.event("label_ready", order_sn=order_sn, package_number=package_number, channel=package.get("logistics_channel_id"))

    sent = _alert_once(
        store,
        sender,
        f"label:{key}",
        f"[SHOPEE TURBO] Etiqueta pronta para imprimir - {order_sn}",
        "A etiqueta do pedido Shopee Turbo foi emitida automaticamente. "
        "Imprima a etiqueta anexada e prepare o pacote imediatamente para cumprir o SLA Turbo.",
        label_file,
    )
    return sent


def run(
    *,
    apply: bool,
    now: int | None = None,
    client: ShopeeClient | None = None,
    shared_root: Path | None = None,
    sender: AlertSender | None = None,
) -> dict[str, Any]:
    current = int(now or time.time())
    shared_root = shared_root or Path(os.environ.get("SHOPVIVALIZ_SHARED_ROOT") or DEFAULT_SHARED_ROOT)
    store = StateStore(shared_root)
    sender = sender or AlertSender()
    client = client or ShopeeClient()

    standard_delay = int(os.environ.get("SHOPEE_AUTOSHIP_STANDARD_DELAY_SECONDS") or DEFAULT_STANDARD_DELAY_SECONDS)
    allow_pickup = _truthy("SHOPEE_AUTOSHIP_ALLOW_PICKUP", False)
    sender_real_name = (os.environ.get("SHOPEE_SENDER_REAL_NAME") or "").strip()
    pickup_address_raw = (os.environ.get("SHOPEE_PICKUP_ADDRESS_ID") or "").strip()
    pickup_address_id = int(pickup_address_raw) if pickup_address_raw.isdigit() else None

    packages = client.search_ready_packages(page_size=100)
    order_sns = sorted({str(row.get("order_sn") or "").strip() for row in packages if row.get("order_sn")})
    details = {str(row.get("order_sn")): row for row in client.get_order_details(order_sns)} if order_sns else {}

    summary: dict[str, Any] = {
        "mode": "apply" if apply else "dry-run",
        "checked_at": datetime.now(timezone.utc).isoformat(),
        "ready_packages": len(packages),
        "eligible": 0,
        "arranged": 0,
        "manual_action": 0,
        "skipped_delay": 0,
        "already_arranged": 0,
        "deferred_by_shopee": 0,
        "label_ready_or_alerted": 0,
        "errors": 0,
    }

    for package in packages:
        order_sn = str(package.get("order_sn") or "").strip()
        package_number = str(package.get("package_number") or "").strip() or None
        channel = int(package.get("logistics_channel_id") or 0)
        key = _package_key(package)
        if not order_sn or not key:
            summary["errors"] += 1
            store.event("invalid_package", package_number=package_number)
            continue
        if package.get("is_shipment_arranged") is True:
            summary["already_arranged"] += 1
            continue
        pending_terms = [str(value) for value in (package.get("pending_terms") or []) if str(value).strip()]
        if pending_terms:
            summary["deferred_by_shopee"] += 1
            store.event("deferred", order_sn=order_sn, package_number=package_number, channel=channel, pending_terms=pending_terms)
            continue
        detail = details.get(order_sn) or {}
        if detail.get("advance_package") is True or detail.get("fulfillment_flag") == "fulfilled_by_shopee":
            summary["deferred_by_shopee"] += 1
            store.event("deferred", order_sn=order_sn, package_number=package_number, channel=channel, reason="shopee_fulfillment")
            continue
        create_time = int(detail.get("create_time") or package.get("create_time") or package.get("update_time") or current)
        age = max(0, current - create_time)
        threshold = minimum_age_seconds(channel, standard_delay=standard_delay)
        if age < threshold:
            summary["skipped_delay"] += 1
            continue
        summary["eligible"] += 1
        try:
            parameter = client.get_shipping_parameter(order_sn, package_number)
            request = build_ship_request(
                order_sn,
                package_number,
                parameter,
                sender_real_name=sender_real_name,
                allow_pickup=allow_pickup,
                pickup_address_id=pickup_address_id,
            )
            if not apply:
                store.event("dry_run_ship", order_sn=order_sn, package_number=package_number, channel=channel, method=next(k for k in ("pickup", "dropoff", "non_integrated") if k in request))
                continue
            client.ship_order(request)
            store.state.setdefault("arranged", {})[key] = {"at": current, "order_sn": order_sn, "channel": channel}
            store.save()
            store.event("ship_order", order_sn=order_sn, package_number=package_number, channel=channel, ok=True)
            summary["arranged"] += 1
            if requires_label_alert(channel):
                if _prepare_turbo_label(client, store, sender, package):
                    summary["label_ready_or_alerted"] += 1
        except ManualActionRequired as exc:
            summary["manual_action"] += 1
            store.event("manual_action", order_sn=order_sn, package_number=package_number, channel=channel, reason=str(exc))
            _alert_once(
                store,
                sender,
                f"manual:{key}",
                f"[SHOPEE] Acao manual necessaria - {order_sn}",
                f"A automacao nao inventou dados para o pedido {order_sn}. Motivo: {exc}. "
                "Abra a Shopee e organize o envio imediatamente.",
            )
        except Exception as exc:
            summary["errors"] += 1
            store.event("error", order_sn=order_sn, package_number=package_number, channel=channel, error=type(exc).__name__, message=str(exc)[:500])
            _alert_once(
                store,
                sender,
                f"error:{key}",
                f"[SHOPEE] Falha na automacao de despacho - {order_sn}",
                f"O worker encontrou uma falha ao organizar o pedido {order_sn}: {type(exc).__name__}: {str(exc)[:300]}",
            )

    # Processed Turbo packages remain visible after ship_order and may need a
    # later cycle before the tracking number / PDF becomes available.
    if apply:
        try:
            processed_turbo = client.search_packages(
                package_status=3,
                fulfillment_type=2,
                invoice_pending=False,
                logistics_channel_ids=sorted(LABEL_ALERT_CHANNELS),
                page_size=100,
            )
            for package in processed_turbo:
                if requires_label_alert(int(package.get("logistics_channel_id") or 0)):
                    try:
                        if _prepare_turbo_label(client, store, sender, package):
                            summary["label_ready_or_alerted"] += 1
                    except Exception as exc:
                        summary["errors"] += 1
                        store.event("label_error", order_sn=package.get("order_sn"), package_number=package.get("package_number"), error=type(exc).__name__, message=str(exc)[:500])
        except Exception as exc:
            summary["errors"] += 1
            store.event("processed_turbo_query_error", error=type(exc).__name__, message=str(exc)[:500])

    store.event("cycle", **summary)
    store.save()
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description="Shopee shipment automation worker")
    parser.add_argument("--apply", action="store_true", help="Call ship_order and prepare Turbo labels")
    args = parser.parse_args()
    try:
        summary = run(apply=args.apply)
    except Exception as exc:
        print(json.dumps({"status": "failed", "error": type(exc).__name__, "message": str(exc)[:500]}, ensure_ascii=False))
        return 2
    print(json.dumps({"status": "ok" if summary.get("errors", 0) == 0 else "degraded", **summary}, ensure_ascii=False, sort_keys=True))
    return 0 if summary.get("errors", 0) == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
