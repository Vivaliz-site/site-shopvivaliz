#!/usr/bin/env python3
"""
Script para enviar boleto Mercado Pago por email via SMTP
"""

import smtplib
import sys
import os
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from dotenv import load_dotenv

# Carregar .env
load_dotenv()

# Configuração
PREFERENCE_ID = "112962856-b34645b8-90e5-45dc-9b50-57b78abfd21a"
CHECKOUT_URL = f"https://www.mercadopago.com.br/checkout/v1/redirect?pref_id={PREFERENCE_ID}"
AMOUNT = "99,90"

SMTP_HOST = os.getenv("SMTP_HOST", "smtp.gmail.com")
SMTP_PORT = int(os.getenv("SMTP_PORT", "587"))
SMTP_USER = os.getenv("SMTP_USER", "fredmourao@gmail.com")
SMTP_PASS = os.getenv("SMTP_PASS", "")
EMAIL_FROM = os.getenv("EMAIL_FROM", "noreply@shopvivaliz.com.br")
EMAIL_TO = "fredmourao@gmail.com"

# Corpo do email
SUBJECT = f"🎫 Boleto de Teste - ShopVivaliz (R$ {AMOUNT})"

BODY_HTML = f"""
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {{ font-family: Arial, sans-serif; color: #333; }}
        .container {{ max-width: 600px; margin: 0 auto; padding: 20px; }}
        .header {{ background: #0f8f62; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }}
        .content {{ background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }}
        .boleto-info {{ background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0f8f62; }}
        .boleto-info strong {{ color: #0f8f62; }}
        .button {{ display: inline-block; background: #0f8f62; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 15px 0; font-weight: bold; }}
        .footer {{ background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; color: #666; }}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Boleto Gerado com Sucesso!</h1>
        </div>
        <div class="content">
            <p>Olá Fredmourao,</p>

            <p>Seu boleto de teste foi gerado com sucesso! Abaixo estão os detalhes:</p>

            <div class="boleto-info">
                <strong>Preference ID:</strong> {PREFERENCE_ID}
            </div>

            <div class="boleto-info">
                <strong>Valor:</strong> R$ {AMOUNT}
            </div>

            <div class="boleto-info">
                <strong>Tipo:</strong> Boleto Bancário
            </div>

            <div class="boleto-info">
                <strong>Status:</strong> Pronto para Pagamento
            </div>

            <p style="text-align: center;">
                <a href="{CHECKOUT_URL}" class="button">🔗 Clique aqui para pagar</a>
            </p>

            <p>Ou acesse este link no seu navegador:</p>
            <p style="word-break: break-all; background: #f0f0f0; padding: 10px; border-radius: 4px;">
                {CHECKOUT_URL}
            </p>

            <h3>📋 Próximos Passos:</h3>
            <ol>
                <li>Clique no link acima</li>
                <li>Escolha "Boleto" como método de pagamento</li>
                <li>Gere ou copie a linha digitável</li>
                <li>Pague ou teste no ambiente de teste</li>
                <li>Webhook será acionado automaticamente</li>
            </ol>

            <p style="color: #666; font-size: 13px;">
                <strong>Nota:</strong> Este é um boleto de teste para validar a integração Mercado Pago no ShopVivaliz.
            </p>
        </div>
        <div class="footer">
            <p>© 2026 ShopVivaliz - Sistema Integrado de Automação</p>
            <p>Email gerado automaticamente - Não responda este email</p>
        </div>
    </div>
</body>
</html>
"""

def enviar_email():
    """Enviar email com boleto"""
    try:
        print(f"📧 Enviando email para: {EMAIL_TO}")
        print(f"   Host: {SMTP_HOST}:{SMTP_PORT}")
        print(f"   Usuário: {SMTP_USER}")
        print("")

        # Conectar ao servidor SMTP
        server = smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=10)
        server.starttls()

        if SMTP_USER and SMTP_PASS:
            print("🔐 Autenticando...")
            server.login(SMTP_USER, SMTP_PASS)

        # Criar mensagem
        msg = MIMEMultipart("alternative")
        msg["Subject"] = SUBJECT
        msg["From"] = EMAIL_FROM
        msg["To"] = EMAIL_TO

        # Versão texto
        body_text = f"""
Olá Fredmourao,

Seu boleto de teste foi gerado com sucesso!

Preference ID: {PREFERENCE_ID}
Valor: R$ {AMOUNT}
Link: {CHECKOUT_URL}

Clique no link acima para acessar o checkout.

Atenciosamente,
Sistema ShopVivaliz
"""

        msg.attach(MIMEText(body_text, "plain"))
        msg.attach(MIMEText(BODY_HTML, "html"))

        # Enviar
        print("✉️  Enviando mensagem...")
        server.sendmail(EMAIL_FROM, EMAIL_TO, msg.as_string())
        server.quit()

        print("")
        print("✅ EMAIL ENVIADO COM SUCESSO!")
        print("")
        print("📋 Detalhes:")
        print(f"   De: {EMAIL_FROM}")
        print(f"   Para: {EMAIL_TO}")
        print(f"   Assunto: {SUBJECT}")
        print(f"   Preference ID: {PREFERENCE_ID}")
        print(f"   Valor: R$ {AMOUNT}")

        return True

    except Exception as e:
        print(f"❌ Erro ao enviar email: {e}")
        print("")
        print("⚠️  Possíveis causas:")
        print("   1. SMTP_USER ou SMTP_PASS não configurados")
        print("   2. Firewall bloqueando conexão")
        print("   3. Credenciais inválidas")
        print("")
        print("Link do boleto (acesso manual):")
        print(f"   {CHECKOUT_URL}")
        return False

if __name__ == "__main__":
    if not SMTP_PASS:
        print("⚠️  SMTP_PASS não configurada no .env")
        print("   Configurar em: C:\\site-shopvivaliz\\.env")
        print("")
        print("   Ou execute com variável de ambiente:")
        print("   set SMTP_PASS=sua_senha && python scripts/send-boleto-email.py")
        sys.exit(1)

    success = enviar_email()
    sys.exit(0 if success else 1)
