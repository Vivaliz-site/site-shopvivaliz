# Email Secrets Aliases

ShopVivaliz production email is Brevo API only. Transportes SMTP/Gmail/Titan e seus aliases de runtime estão aposentados.

Canonical current runtime:
- BREVO_API_KEY — Brevo API credential.
- EMAIL_TO — default recipient list when a job needs one.
- Sender and reply-to are fixed by application policy as atendimento@shopvivaliz.com.br.

Aposentados do runtime (não restaurar nem materializar):
- SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS
- EMAIL_SMTP_HOST, EMAIL_SMTP_PORT, EMAIL_USER, EMAIL_PASSWORD, EMAIL_FROM
- MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS
- EMAIL_AGENTES_SECRET

Os nomes aposentados só podem aparecer em código de migração/guard e documentação histórica que os identifique explicitamente como aposentados. Código novo deve usar os helpers da API Brevo.

Validation:

    python scripts/automation/validate_email_config.py

The validator writes logs/email-config-check.json and never prints credential values.
