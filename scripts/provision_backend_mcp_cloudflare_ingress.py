#!/usr/bin/env python3
"""Inspeciona, aplica ou reverte o ingress Cloudflare Access + Tunnel para o MCP do backend.

Reutiliza o tunnel existente `shopvivaliz-oci-backend-admin` (89dd623d-3b21-4377-aa96-11a9d93c167f),
preservando integralmente `admin-backend.shopvivaliz.com.br` e adicionando apenas
`mcp-backend.shopvivaliz.com.br` -> `http://127.0.0.1:5590`.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

ACCOUNT_ID = "0afb7d99f708b9455598df9fa1035164"
TUNNEL_ID = "89dd623d-3b21-4377-aa96-11a9d93c167f"
TUNNEL_NAME = "shopvivaliz-oci-backend-admin"
TEAM_DOMAIN = "gentle-credit-8885.cloudflareaccess.com"
MCP_HOSTNAME = "mcp-backend.shopvivaliz.com.br"
ADMIN_HOSTNAME = "admin-backend.shopvivaliz.com.br"
ORIGIN_SERVICE = "http://127.0.0.1:5590"
OWNER_EMAIL = "fredmourao@gmail.com"
APP_NAME = "ShopVivaliz Backend MCP"
POLICY_NAME = "ShopVivaliz Backend MCP Owner Only"
GATEWAY_CONFIG_PATH = Path("/var/lib/shopvivaliz-remote-control/access-gateway.json")
ALLOWED_REDIRECTS = [
    "https://claude.ai/api/mcp/auth_callback",
    "https://claude.com/api/mcp/auth_callback",
    "https://chatgpt.com/connector_platform_oauth_redirect",
]
API = "https://api.cloudflare.com/client/v4"


def cf_request(path: str, token: str, method: str = "GET", payload: dict | None = None) -> dict:
    headers = {"Authorization": f"Bearer {token}", "Accept": "application/json"}
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(f"{API}{path}", data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            body = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as err:
        raise SystemExit(f"CLOUDFLARE_HTTP_{err.code} method={method} path={path.split('?')[0]}")
    if body.get("success") is not True:
        raise SystemExit(f"CLOUDFLARE_UNSUCCESSFUL method={method} path={path.split('?')[0]}")
    return body


def get_zone_id(dns_token: str) -> str:
    res = cf_request("/zones?name=shopvivaliz.com.br&status=active&per_page=5", dns_token)
    zones = res.get("result") or []
    if len(zones) != 1 or not zones[0].get("id"):
        raise SystemExit("ZONE_LOOKUP_FAILED")
    return str(zones[0]["id"])


def inspect_state(cf_token: str, dns_token: str) -> dict:
    tunnel_cfg = cf_request(f"/accounts/{ACCOUNT_ID}/cfd_tunnel/{TUNNEL_ID}/configurations", cf_token)
    config_obj = (tunnel_cfg.get("result") or {}).get("config") or {}
    ingress = config_obj.get("ingress") or []
    hostnames = [str(r.get("hostname") or "") for r in ingress if isinstance(r, dict) and r.get("hostname")]
    print("TUNNEL_INGRESS_HOSTNAMES=" + ",".join(hostnames))

    apps = cf_request(f"/accounts/{ACCOUNT_ID}/access/apps?per_page=100", cf_token).get("result") or []
    mcp_apps = [a for a in apps if isinstance(a, dict) and a.get("domain") == MCP_HOSTNAME]
    print(f"MCP_ACCESS_APP_PRESENT={str(bool(mcp_apps)).lower()}")

    zone_id = get_zone_id(dns_token)
    dns_records = cf_request(
        f"/zones/{zone_id}/dns_records?type=CNAME&name={MCP_HOSTNAME}&per_page=10", dns_token
    ).get("result") or []
    print(f"MCP_DNS_CNAME_PRESENT={str(bool(dns_records)).lower()}")
    return {
        "config_obj": config_obj,
        "ingress": ingress,
        "mcp_apps": mcp_apps,
        "zone_id": zone_id,
        "dns_records": dns_records,
    }


def write_gateway_config(aud: str) -> None:
    payload = json.dumps(
        {
            "team_domain": TEAM_DOMAIN,
            "audience": aud,
            "allowed_emails": [OWNER_EMAIL],
            "allowed_service_tokens": [],
        },
        indent=2,
    ) + "\n"
    subprocess.run(
        [
            "sudo",
            "-n",
            "python3",
            "-c",
            (
                "import os, pathlib, sys; "
                "p = pathlib.Path(sys.argv[1]); "
                "tmp = p.with_suffix('.tmp'); "
                "fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600); "
                "os.write(fd, sys.argv[2].encode('utf-8')); "
                "os.close(fd); "
                "os.chown(tmp, 0, 0); "
                "os.replace(tmp, p)"
            ),
            str(GATEWAY_CONFIG_PATH),
            payload,
        ],
        check=True,
    )
    subprocess.run(["sudo", "-n", "bash", "scripts/setup-remote-control-access-gateway.sh"], check=True)


def ensure_access_app(cf_token: str, existing_apps: list[dict]) -> tuple[str, str]:
    app_payload = {
        "name": APP_NAME,
        "domain": MCP_HOSTNAME,
        "type": "self_hosted",
        "session_duration": "24h",
        "app_launcher_visible": False,
        "oauth_configuration": {
            "enabled": True,
            "dynamic_client_registration": {
                "enabled": True,
                "allow_any_on_localhost": False,
                "allow_any_on_loopback": False,
                "allowed_uris": ALLOWED_REDIRECTS,
            },
        },
    }
    if existing_apps:
        app_id = str(existing_apps[0]["id"])
        res = cf_request(f"/accounts/{ACCOUNT_ID}/access/apps/{app_id}", cf_token, "PUT", app_payload)["result"]
    else:
        res = cf_request(f"/accounts/{ACCOUNT_ID}/access/apps", cf_token, "POST", app_payload)["result"]
        app_id = str(res["id"])
    aud = str(res.get("aud") or "")
    if not app_id or not aud:
        raise SystemExit("ACCESS_APP_MISSING_ID_OR_AUD")

    policies = cf_request(f"/accounts/{ACCOUNT_ID}/access/apps/{app_id}/policies?per_page=50", cf_token).get("result") or []
    owner_pol = [p for p in policies if isinstance(p, dict) and p.get("name") == POLICY_NAME]
    pol_body = {
        "name": POLICY_NAME,
        "decision": "allow",
        "include": [{"email": {"email": OWNER_EMAIL}}],
        "precedence": 1,
        "session_duration": "24h",
    }
    if not owner_pol:
        cf_request(f"/accounts/{ACCOUNT_ID}/access/apps/{app_id}/policies", cf_token, "POST", pol_body)
    return app_id, aud


def ensure_tunnel_ingress(cf_token: str, config_obj: dict, ingress: list[dict]) -> None:
    if not any(isinstance(r, dict) and r.get("hostname") == ADMIN_HOSTNAME for r in ingress):
        raise SystemExit("ADMIN_BACKEND_INGRESS_NOT_FOUND_REFUSING_WRITE")
    new_ingress = [
        r for r in ingress
        if not (isinstance(r, dict) and r.get("hostname") == MCP_HOSTNAME)
    ]
    catch_all = [r for r in new_ingress if isinstance(r, dict) and not r.get("hostname")]
    routed = [r for r in new_ingress if isinstance(r, dict) and r.get("hostname")]
    if not catch_all:
        catch_all = [{"service": "http_status:404"}]
    routed.append({"hostname": MCP_HOSTNAME, "service": ORIGIN_SERVICE})
    updated_cfg = dict(config_obj)
    updated_cfg["ingress"] = routed + catch_all
    cf_request(
        f"/accounts/{ACCOUNT_ID}/cfd_tunnel/{TUNNEL_ID}/configurations",
        cf_token,
        "PUT",
        {"config": updated_cfg},
    )


def ensure_dns_cname(dns_token: str, zone_id: str, records: list[dict]) -> None:
    target = f"{TUNNEL_ID}.cfargotunnel.com"
    if len(records) == 1:
        rec = records[0]
        if rec.get("content") == target and rec.get("proxied") is True:
            return
        cf_request(
            f"/zones/{zone_id}/dns_records/{rec['id']}",
            dns_token,
            "PUT",
            {"type": "CNAME", "name": MCP_HOSTNAME, "content": target, "ttl": 1, "proxied": True},
        )
    elif len(records) == 0:
        cf_request(
            f"/zones/{zone_id}/dns_records",
            dns_token,
            "POST",
            {"type": "CNAME", "name": MCP_HOSTNAME, "content": target, "ttl": 1, "proxied": True},
        )
    else:
        raise SystemExit("DUPLICATE_DNS_RECORDS")


def rollback(cf_token: str, dns_token: str, state: dict) -> None:
    for rec in state["dns_records"]:
        cf_request(f"/zones/{state['zone_id']}/dns_records/{rec['id']}", dns_token, "DELETE")
    ingress = state["ingress"]
    filtered = [r for r in ingress if not (isinstance(r, dict) and r.get("hostname") == MCP_HOSTNAME)]
    if filtered != ingress:
        updated_cfg = dict(state["config_obj"])
        updated_cfg["ingress"] = filtered
        cf_request(
            f"/accounts/{ACCOUNT_ID}/cfd_tunnel/{TUNNEL_ID}/configurations",
            cf_token,
            "PUT",
            {"config": updated_cfg},
        )
    for app in state["mcp_apps"]:
        cf_request(f"/accounts/{ACCOUNT_ID}/access/apps/{app['id']}", cf_token, "DELETE")
    print("BACKEND_MCP_INGRESS_ROLLBACK=PASS")


def main() -> int:
    cf_token = os.environ.get("CF_TOKEN", "").strip()
    dns_token = os.environ.get("CF_DNS_TOKEN", "").strip() or cf_token
    action = os.environ.get("ACTION", "inspect").strip()
    confirmation = os.environ.get("CONFIRMATION", "").strip()
    if not cf_token:
        raise SystemExit("NO_CLOUDFLARE_TOKEN")
    state = inspect_state(cf_token, dns_token)
    if action == "inspect":
        print("BACKEND_MCP_INGRESS_INSPECT=PASS")
        return 0
    if action == "rollback":
        if confirmation != "ROLLBACK_BACKEND_MCP_INGRESS":
            raise SystemExit("ROLLBACK_CONFIRMATION_REQUIRED")
        rollback(cf_token, dns_token, state)
        return 0
    if action != "apply" or confirmation != "APPLY_BACKEND_MCP_INGRESS":
        raise SystemExit("APPLY_CONFIRMATION_REQUIRED")

    app_id, aud = ensure_access_app(cf_token, state["mcp_apps"])
    print(f"MCP_ACCESS_APP_CONFIGURED=true app_id={app_id}")
    write_gateway_config(aud)
    print("GATEWAY_SERVICE_ACTIVE=true")
    ensure_tunnel_ingress(cf_token, state["config_obj"], state["ingress"])
    print("TUNNEL_INGRESS_UPDATED=true")
    ensure_dns_cname(dns_token, state["zone_id"], state["dns_records"])
    print("MCP_DNS_CONFIGURED=true")
    print("BACKEND_MCP_INGRESS_APPLY=PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
