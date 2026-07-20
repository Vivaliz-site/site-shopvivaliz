#!/usr/bin/env python3
"""ShopVivaliz 24/7 Audit Monitor"""
import json, os, time, requests
from datetime import datetime
from pathlib import Path

BASE_URL = "https://shopvivaliz.com.br"
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
    log_file = LOG_DIR / f"audit-24-7-{datetime.now().strftime('%Y-%m-%d')}.log"
    entry = {"timestamp": datetime.now().isoformat(), "type": event_type, "status": status, **details}
    with open(log_file, "a") as f:
        f.write(json.dumps(entry, ensure_ascii=False) + "\n")
    return entry

def check_endpoint(name: str, path: str, expected_status: int = 200) -> bool:
    try:
        resp = requests.get(f"{BASE_URL}{path}", timeout=10)
        success = resp.status_code == expected_status
        log_event("endpoint_check", "ok" if success else "fail", {
            "endpoint": name, "path": path, "status_code": resp.status_code, "expected": expected_status
        })
        return success
    except Exception as e:
        log_event("endpoint_check", "error", {"endpoint": name, "path": path, "error": str(e)})
        return False

def check_olist_sync() -> dict:
    try:
        resp = requests.get(f"{BASE_URL}/api/orders/health.php", timeout=10)
        data = resp.json() if resp.text else {}
        status = "ok" if data.get("sync_status") == "ok" else "fail"
        log_event("olist_sync", status, data)
        return {"status": status, "data": data}
    except Exception as e:
        log_event("olist_sync", "error", {"error": str(e)})
        return {"status": "error", "error": str(e)}

def run_audit():
    print(f"🔍 Audit started: {datetime.now().isoformat()}")
    env = load_env()
    
    endpoints_ok = all([
        check_endpoint("Home", "/", 200),
        check_endpoint("Catalog", "/catalogo.php", 200),
        check_endpoint("Checkout", "/checkout/index.php", 200),
    ])
    
    olist_sync = check_olist_sync()
    
    status = {
        "timestamp": datetime.now().isoformat(),
        "overall_status": "green" if endpoints_ok and olist_sync["status"] == "ok" else "red",
        "endpoints_ok": endpoints_ok,
        "olist_sync": olist_sync["status"],
    }
    
    status_file = LOG_DIR / "health-status-latest.json"
    with open(status_file, "w") as f:
        json.dump(status, f, indent=2, ensure_ascii=False)
    
    print(f"✅ Audit complete: {status['overall_status'].upper()}")
    return status

if __name__ == "__main__":
    run_audit()
