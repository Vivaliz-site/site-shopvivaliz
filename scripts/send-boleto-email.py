#!/usr/bin/env python3
"""
Script para enviar boleto Mercado Pago pelo provider transacional ShopVivaliz.
"""

import sys
from dotenv import load_dotenv
from shopvivaliz_mail import send_html

# Carregar .env
load_dotenv()

# Configuração
PREFERENCE_ID = "112962856-b34645b8-90e5-45dc-9b50-57b78abfd21a"
CHECKOUT_URL = f"https://www.mercadopago.com.br/checkout/v1/redirect?pref_id={PREFERENCE_ID}"
AMOUNT = "99,90"

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
    """Enviar email com boleto via Brevo API com identidade fixa ShopVivaliz."""
    print(f"📧 Enviando email para: {EMAIL_TO}")
    result = send_html(
        EMAIL_TO,
        SUBJECT,
        BODY_HTML,
        tags=["shopvivaliz-transactional", "boleto-test"],
    )
    if result.success:
        print("✅ EMAIL ENVIADO COM SUCESSO!")
        print("   Provider: Brevo API")
        print("   De: ShopVivaliz <atendimento@shopvivaliz.com.br>")
        print(f"   Para: {EMAIL_TO}")
        print(f"   Assunto: {SUBJECT}")
        return True

    print(f"❌ Falha no envio: {result.error}")
    print("Link do boleto (acesso manual):")
    print(f"   {CHECKOUT_URL}")
    return False

if __name__ == "__main__":
    success = enviar_email()
    sys.exit(0 if success else 1)
