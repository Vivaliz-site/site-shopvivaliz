#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TARGETS = [
    "scripts/mailer.php",
    "includes/OrderNotificationService.class.php",
    "api/autonomous/send-email.php",
    "api/generate-boleto-email.php",
    "scripts/shopee_logistics_worker.py",
    "scripts/stock-alerts-email-cron.py",
    "config/constants.php",
    "config/secrets.py",
    "config/secrets-groups.json",
    "scripts/add-secrets.py",
    "scripts/enable-email-in-production.sh",
    "scripts/deploy-diagnostic.py",
]
FORBIDDEN = (
    "smtp.titan.email",
    "smtp.gmail.com",
)

errors = []
for rel in TARGETS:
    text = (ROOT / rel).read_text(encoding="utf-8", errors="replace").lower()
    for needle in FORBIDDEN:
        if needle in text:
            errors.append(f"{rel}: forbidden provider marker {needle}")

config = (ROOT / "config" / "secrets-groups.json").read_text(encoding="utf-8")
if '"BREVO_API_KEY"' not in config:
    errors.append("config/secrets-groups.json: BREVO_API_KEY not canonical")
workflow = (ROOT / ".github" / "workflows" / "merge-runtime-credential-union.yml").read_text(encoding="utf-8")
if "email = Brevo API + destinatario" not in workflow:
    errors.append("credential workflow: email scope description is not Brevo-only")

if errors:
    raise SystemExit("\n".join(errors))
print("shopvivaliz_mail_legacy_provider_guard=PASS")
