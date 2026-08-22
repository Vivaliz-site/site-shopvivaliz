#!/usr/bin/env python3
"""
Stock Alerts Email CRON — Task-033 Fase 2
Envia notificações de email quando produtos voltam ao estoque
"""

import os
import sys
import sqlite3
import json
import smtplib
from html import escape
from datetime import datetime
from pathlib import Path
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

# Configurações
DB_PATH = Path(__file__).parent.parent / "data" / "shopvivaliz.db"
SMTP_HOST = os.getenv("SMTP_HOST") or os.getenv("EMAIL_SMTP_HOST") or os.getenv("MAIL_HOST") or "localhost"
try:
    SMTP_PORT = int(os.getenv("SMTP_PORT") or os.getenv("EMAIL_SMTP_PORT") or os.getenv("MAIL_PORT") or "587")
except ValueError:
    SMTP_PORT = 587
SMTP_USER = os.getenv("SMTP_USER") or os.getenv("EMAIL_USER") or os.getenv("MAIL_USER") or ""
SMTP_PASS = os.getenv("SMTP_PASS") or os.getenv("EMAIL_PASSWORD") or os.getenv("MAIL_PASS") or ""
EMAIL_FROM = os.getenv("EMAIL_FROM") or SMTP_USER or "noreply@shopvivaliz.com.br"

# Template HTML do email
EMAIL_TEMPLATE = """
<html>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
  <div style="max-width: 600px; margin: 0 auto;">
    <h2 style="color: #0b4f88;">✓ Produto disponível novamente!</h2>
    <p>Olá,</p>
    <p>O produto que você se inscreveu para receber notificação voltou ao estoque:</p>

    <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;">
      <strong style="font-size: 18px; color: #0b4f88;">{product_name}</strong><br>
      SKU: {sku}<br>
      <a href="https://shopvivaliz.com.br/produto?sku={sku}"
         style="display: inline-block; margin-top: 10px; padding: 10px 20px; background: #0b4f88; color: white; text-decoration: none; border-radius: 5px;">
        Ver Produto
      </a>
    </div>

    <p>Aproveite e faça sua compra antes que acabe novamente!</p>

    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="font-size: 12px; color: #999;">
      Para desincrever-se desta notificação,
      <a href="https://shopvivaliz.com.br/api/catalog/stock-alert.php?unsubscribe={unsubscribe_token}" style="color: #0b4f88;">clique aqui</a>.
    </p>
  </div>
</body>
</html>
"""


def get_db_connection():
    """Conectar ao banco de dados SQLite"""
    if not DB_PATH.exists():
        print(f"[ERRO] Banco de dados não encontrado: {DB_PATH}")
        return None
    return sqlite3.connect(str(DB_PATH))


def load_erp_catalog_by_sku():
    """Ler cache derivado do ERP Olist/Tiny v3, sem consultar products.stock."""
    root = Path(__file__).parent.parent
    candidates = [
        root / "api" / "catalog" / "fallback-products.json",
        root / "storage" / "products-cache-ativos.json",
    ]
    for path in candidates:
        if not path.exists():
            continue
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:
            print(f"[AVISO] Falha ao ler catalogo ERP {path}: {exc}")
            continue
        rows = payload
        if isinstance(payload, dict):
            for key in ("itens", "items", "produtos", "products", "data"):
                if isinstance(payload.get(key), list):
                    rows = payload[key]
                    break
        if not isinstance(rows, list):
            continue
        indexed = {}
        for row in rows:
            if not isinstance(row, dict):
                continue
            sku = str(row.get("sku") or row.get("codigo") or "").strip()
            if not sku:
                continue
            indexed[sku] = row
        if indexed:
            return indexed
    return {}


def get_back_in_stock_alerts():
    """Obter alertas usando estoque/nome derivados do ERP Olist/Tiny v3."""
    catalog = load_erp_catalog_by_sku()
    if not catalog:
        print("[ERRO] Catalogo ERP derivado nao encontrado; alertas nao enviados")
        return []

    conn = get_db_connection()
    if not conn:
        return []

    try:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT email, sku, unsubscribe_token
            FROM stock_alerts
            WHERE notified_at IS NULL
            ORDER BY created_at ASC
            LIMIT 100
        """)
        alerts = []
        for email, sku, unsubscribe_token in cursor.fetchall():
            product = catalog.get(str(sku))
            if not isinstance(product, dict):
                continue
            stock = int(float(product.get("stock") or product.get("estoque_disponivel") or 0))
            if stock <= 0:
                continue
            name = str(product.get("name") or product.get("descricao") or sku)
            alerts.append((email, sku, name, stock, unsubscribe_token))
        return alerts
    except Exception as e:
        print(f"[ERRO] Falha ao consultar alertas: {e}")
        return []
    finally:
        conn.close()


def send_email(email_to, product_name, sku, unsubscribe_token):
    """Enviar email de notificação"""
    if "@" not in str(email_to) or "." not in str(email_to):
        print(f"[AVISO] Email invalido ignorado: {email_to}")
        return False

    if not SMTP_USER or not SMTP_PASS:
        print(f"[AVISO] Credenciais SMTP não configuradas - skipando envio real")
        return False

    try:
        msg = MIMEMultipart("alternative")
        msg["Subject"] = f"✓ {product_name} voltou ao estoque!"
        msg["From"] = EMAIL_FROM
        msg["To"] = email_to

        html_body = EMAIL_TEMPLATE.format(
            product_name=escape(str(product_name)),
            sku=escape(str(sku)),
            unsubscribe_token=escape(str(unsubscribe_token))
        )

        msg.attach(MIMEText(html_body, "html"))

        if SMTP_PORT == 465:
            server = smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT, timeout=30)
        else:
            server = smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=30)

        with server:
            if SMTP_PORT != 465:
                server.starttls()
            server.login(SMTP_USER, SMTP_PASS)
            server.send_message(msg)

        print(f"[✓] Email enviado para {email_to} (SKU: {sku})")
        return True
    except Exception as e:
        print(f"[ERRO] Falha ao enviar email para {email_to}: {e}")
        return False


def mark_as_notified(sku, email):
    """Marcar notificação como enviada no banco"""
    conn = get_db_connection()
    if not conn:
        return False

    try:
        cursor = conn.cursor()
        cursor.execute("""
            UPDATE stock_alerts
            SET notified_at = ?, notified_count = COALESCE(notified_count, 0) + 1
            WHERE sku = ? AND email = ? AND notified_at IS NULL
        """, (datetime.utcnow().isoformat(), sku, email))
        conn.commit()
        return cursor.rowcount > 0
    except Exception as e:
        print(f"[ERRO] Falha ao atualizar banco: {e}")
        return False
    finally:
        conn.close()


def main():
    """Execução principal"""
    print(f"[{datetime.now().isoformat()}] Iniciando Stock Alerts Email CRON")

    alerts = get_back_in_stock_alerts()
    if not alerts:
        print("[OK] Nenhum produto para notificar")
        return 0

    print(f"[OK] Encontrados {len(alerts)} alertas para processar")

    sent_count = 0
    for email_to, sku, product_name, stock, unsubscribe_token in alerts:
        if send_email(email_to, product_name, sku, unsubscribe_token):
            if mark_as_notified(sku, email_to):
                sent_count += 1

    print(f"[{datetime.now().isoformat()}] CRON finalizado - {sent_count} emails enviados")
    return 0


if __name__ == "__main__":
    sys.exit(main())
