#!/usr/bin/env python3
"""
ShopVivaliz 24/7 Audit Monitor
Monitoramento contínuo de produção com alertas automáticos
"""
import json
import os
import sys
import time
import requests
import subprocess
from datetime import datetime
from pathlib import Path

BASE_URL = "https://dev.shopvivaliz.com.br"
LOG_DIR = Path(__file__).parent.parent / "logs"
LOG_DIR.mkdir(exist_ok=True)

def load_env():
    env = {}
    env_file = Path(__file__).parent.parent / ".env"
    if env_file.exists():
        with open(env_file) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    env[k.strip()] = v.strip().strip('\'"')
    return env

def log_event(event_type: str, status: str, details: dict):
    """Log estruturado de eventos"""
    log_file = LOG_DIR / f"audit-24-7-{datetime.now().strftime('%Y-%m-%d')}.log"
    entry = {
        "timestamp": datetime.now().isoformat(),
        "type": event_type,
        "status": status,
        **details
    }
    with open(log_file, "a") as f:
        f.write(json.dumps(entry, ensure_ascii=False) + "\n")
    return entry

def check_endpoint(name: str, path: str, expected_status: int = 200) -> bool:
    """Verifica disponibilidade de endpoint"""
    try:
        resp = requests.get(f"{BASE_URL}{path}", timeout=10)
        success = resp.status_code == expected_status
        log_event("endpoint_check", "ok" if success else "fail", {
            "endpoint": name,
            "path": path,
            "status_code": resp.status_code,
            "expected": expected_status
        })
        return success
    except Exception as e:
        log_event("endpoint_check", "error", {
            "endpoint": name,
            "path": path,
            "error": str(e)
        })
        return False

def check_olist_sync() -> dict:
    """Verifica se ordens estão sincronizando com Olist"""
    try:
        resp = requests.get(f"{BASE_URL}/api/orders/health.php", timeout=10)
        data = resp.json() if resp.text else {}

        status = "ok" if data.get("sync_status") == "ok" else "fail"
        log_event("olist_sync", status, data)
        return {"status": status, "data": data}
    except Exception as e:
        log_event("olist_sync", "error", {"error": str(e)})
        return {"status": "error", "error": str(e)}

def check_integrations() -> dict:
    """Verifica status de todas as integrações"""
    integrations = {
        "olist": check_endpoint("Olist API", "/api/olist/webhook-health.php"),
        "shopee": check_endpoint("Shopee Sync", "/api/shopee/health.php", 404),
        "melhorenvio": check_endpoint("MelhorEnvio", "/api/melhorenvio/shipping-check.php", 400),
        "catalog": check_endpoint("Catálogo", "/api/catalog/products.php"),
        "orders": check_endpoint("Pedidos", "/api/orders/health.php"),
    }

    log_event("integrations_check", "ok", integrations)
    return integrations

def refresh_olist_token(env: dict) -> bool:
    """Renova token Olist se expirado"""
    try:
        resp = requests.get(f"{BASE_URL}/api/olist/refresh-token.php", timeout=30)
        data = resp.json() if resp.text else {}

        if data.get("status") == "ok":
            log_event("token_refresh", "ok", {
                "token": "olist",
                "refresh_rotated": data.get("refresh_rotated")
            })
            return True
        else:
            log_event("token_refresh", "fail", {
                "token": "olist",
                "error": data.get("message")
            })
            return False
    except Exception as e:
        log_event("token_refresh", "error", {"token": "olist", "error": str(e)})
        return False

def save_health_status():
    """Salva status atual em JSON para dashboard"""
    status_file = LOG_DIR / "health-status-latest.json"

    endpoints_ok = all([
        check_endpoint("Home", "/", 200),
        check_endpoint("Catalog", "/catalogo.php", 200),
        check_endpoint("Checkout", "/checkout/index.php", 200),
    ])

    olist_sync = check_olist_sync()
    integrations = check_integrations()

    status = {
        "timestamp": datetime.now().isoformat(),
        "overall_status": "green" if endpoints_ok and olist_sync["status"] == "ok" else "red",
        "endpoints_ok": endpoints_ok,
        "olist_sync": olist_sync["status"],
        "integrations": integrations,
    }

    with open(status_file, "w") as f:
        json.dump(status, f, indent=2, ensure_ascii=False)

    return status

def send_alert(subject: str, body: str, env: dict):
    """Envia alerta por email"""
    try:
        import smtplib
        from email.mime.text import MIMEText
        from email.mime.multipart import MIMEMultipart

        host = env.get("SMTP_HOST", "smtp.gmail.com")
        port = int(env.get("SMTP_PORT", "587"))
        user = env.get("SMTP_USER", "")
        password = env.get("SMTP_PASS", "")
        to_addr = env.get("EMAIL_TO", "fredmourao@gmail.com")

        if not user or not password:
            return False

        msg = MIMEMultipart()
        msg["From"] = env.get("EMAIL_FROM", user)
        msg["To"] = to_addr
        msg["Subject"] = f"🚨 ShopVivaliz Alert: {subject}"

        msg.attach(MIMEText(body, "html"))

        with smtplib.SMTP(host, port) as server:
            server.starttls()
            server.login(user, password)
            server.send_message(msg)

        log_event("alert_sent", "ok", {"subject": subject, "to": to_addr})
        return True
    except Exception as e:
        log_event("alert_sent", "error", {"error": str(e)})
        return False

def run_audit():
    """Executa auditoria completa"""
    print(f"🔍 Auditoria 24/7 iniciada: {datetime.now().isoformat()}")

    env = load_env()

    # 1. Verificar endpoints
    print("  → Checando endpoints...")
    health = save_health_status()

    # 2. Renovar token se necessário
    print("  → Verificando tokens...")
    token_ok = refresh_olist_token(env)

    # 3. Verificar integrações
    print("  → Checando integrações...")
    integrations = check_integrations()

    # 4. Alertar se há problemas
    if health["overall_status"] == "red":
        alert_body = f"""
        <h2>⚠️ Problema detectado em produção</h2>
        <p><strong>Status Geral:</strong> {health['overall_status']}</p>
        <p><strong>Endpoints:</strong> {'✅ OK' if health['endpoints_ok'] else '❌ ERRO'}</p>
        <p><strong>Sync Olist:</strong> {health['olist_sync']}</p>
        <p><strong>Hora:</strong> {datetime.now().isoformat()}</p>
        <p><strong>Action:</strong> Verifique https://dev.shopvivaliz.com.br/admin/monitor/</p>
        """
        send_alert("Production Issue Detected", alert_body, env)

    print(f"✅ Auditoria concluída: {health['overall_status'].upper()}")
    return health

if __name__ == "__main__":
    run_audit()
