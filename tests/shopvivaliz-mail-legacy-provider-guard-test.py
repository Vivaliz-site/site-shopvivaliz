#!/usr/bin/env python3
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TARGETS = [
    "scripts/mailer.php",
    "includes/OrderNotificationService.class.php",
    "api/autonomous/send-email.php",
    "api/generate-boleto-email.php",
    "scripts/shopee_logistics_worker.py",
    "scripts/stock-alerts-email-cron.py",
    "scripts/shopvivaliz_mail.py",
    "scripts/send-boleto-email.py",
    "scripts/shopvivaliz_notify.py",
    "scripts/send_email.py",
    "scripts/send-audit-report.php",
    "scripts/send-audit-report-smtp.php",
    "scripts/smart-notifications.php",
    "scripts/metrics-proactive-monitor.php",
    "scripts/watchdog-health-check.php",
    "scripts/security-scanner.php",
    "scripts/disaster-recovery.php",
    "scripts/retry-exponential-backoff.php",
    "scripts/send-notifications.php",
    "scripts/auditoria-24-7.py",
    "scripts/automation/hourly_status_email.py",
    "scripts/automation/eight_hour_status_email.py",
    "scripts/automation/send_report.py",
    "config/constants.php",
    "config/secrets.py",
    "config/secrets-groups.json",
    "scripts/add-secrets.py",
    "scripts/enable-email-in-production.sh",
    "scripts/deploy-diagnostic.py",
    "scripts/retire-legacy-email-runtime-keys.py",
    "checkout-v2/index.php",
    "claude/constants.php",
]
FORBIDDEN = (
    "smtp.titan.email",
    "smtp.gmail.com",
    "import smtplib",
    "smtplib.",
)

errors = []
for rel in TARGETS:
    path = ROOT / rel
    text = path.read_text(encoding="utf-8", errors="replace").lower()
    for needle in FORBIDDEN:
        if needle in text:
            errors.append(f"{rel}: forbidden provider marker {needle}")
    code_without_line_comments = re.sub(r"(?m)^\s*(//|#).*$", "", text)
    if path.suffix == ".php" and re.search(r"(?<![a-z_])mail\s*\(", code_without_line_comments):
        errors.append(f"{rel}: native PHP mail() fallback is forbidden")

config = (ROOT / "config" / "secrets-groups.json").read_text(encoding="utf-8")
if '"BREVO_API_KEY"' not in config:
    errors.append("config/secrets-groups.json: BREVO_API_KEY not canonical")
workflow = (ROOT / ".github" / "workflows" / "merge-runtime-credential-union.yml").read_text(encoding="utf-8")
if "email = Brevo API + destinatario" not in workflow:
    errors.append("credential workflow: email scope description is not Brevo-only")

legacy_runtime_secret_names = (
    "EMAIL_PASSWORD", "EMAIL_SMTP_HOST", "EMAIL_SMTP_PORT", "EMAIL_USER",
    "SMTP_HOST", "SMTP_PORT", "SMTP_USER", "SMTP_PASS",
    "MAIL_HOST", "MAIL_PORT", "MAIL_USER", "MAIL_PASS",
)
merge_writer = (ROOT / "scripts" / "merge-runtime-credential-union.py").read_text(encoding="utf-8")
workflow_text = workflow
for key in legacy_runtime_secret_names:
    if f'"{key}"' in merge_writer:
        errors.append(f"credential merge writer still allows retired key {key}")
    if f"secrets.{key}" in workflow_text:
        errors.append(f"credential workflow still imports retired key {key}")

if errors:
    raise SystemExit("\n".join(errors))
print("shopvivaliz_mail_legacy_provider_guard=PASS")
