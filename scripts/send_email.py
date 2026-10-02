#!/usr/bin/env python3
import os
import sys
from pathlib import Path

from shopvivaliz_mail import send_text

DEFAULT_ATTACHMENT_PATHS = [Path("planilhas/shopee_import.xlsx")]

def find_attachment() -> Path:
    for path in DEFAULT_ATTACHMENT_PATHS:
        if path.exists():
            return path
    raise FileNotFoundError(
        "No spreadsheet found. Expected one of: "
        + ", ".join(str(path) for path in DEFAULT_ATTACHMENT_PATHS)
    )

def parse_recipients(raw: str) -> list[str]:
    recipients = [item.strip() for item in raw.split(",") if item.strip()]
    if not recipients or any("@" not in item or "." not in item for item in recipients):
        raise ValueError("Invalid EMAIL_TO recipients")
    return recipients

def main(argv=None) -> int:
    email_to = (os.getenv("EMAIL_TO") or "").strip()
    if not email_to:
        print("ERROR: Missing required environment variable: EMAIL_TO", file=sys.stderr)
        return 1
    try:
        attachment_path = find_attachment()
        recipients = parse_recipients(email_to)
        attachment = (attachment_path.name, attachment_path.read_bytes())
    except (FileNotFoundError, ValueError, OSError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1

    print(f"Using attachment: {attachment_path}")
    result = send_text(
        recipients,
        "Planilha Shopee Gerada - ShopVivaliz",
        "Segue em anexo a planilha pronta para importação na Shopee.",
        tags=["shopvivaliz-transactional", "shopee-spreadsheet"],
        attachments=[attachment],
    )
    if not result.success:
        print(f"ERROR: failed to send email: {result.error}", file=sys.stderr)
        return 1
    print("Email sent successfully via Brevo API.")
    return 0

if __name__ == "__main__":
    sys.exit(main())
