# Email Secrets Aliases

Use GitHub Secrets for Actions and environment variables for local or server runtime.
Do not put real passwords in tracked PHP, Python, shell, Markdown, or `.env.example` files.

Canonical accepted groups:

- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`
- `EMAIL_SMTP_HOST`, `EMAIL_SMTP_PORT`, `EMAIL_USER`, `EMAIL_PASSWORD`
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`

Required recipients:

- `EMAIL_TO`, comma-separated when there is more than one recipient.
- `EMAIL_FROM`, optional; defaults to the configured SMTP user where supported.

Current automated report recipients:

- `fredmourao@gmail.com`
- `atendimento@shopvivaliz.com.br`

Validation:

```bash
python scripts/automation/validate_email_config.py
```

The validator writes `logs/email-config-check.json` and never prints secret values.
