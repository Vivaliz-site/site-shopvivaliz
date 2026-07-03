#!/usr/bin/env python3
"""
Setup Auto-Deploy — ShopVivaliz
Configura deploy automático sem GitHub Actions, sem custo.

O que faz:
  1. Sobe deploy-webhook.php para o servidor via FTP
  2. Gera DEPLOY_SECRET e salva no .env do servidor
  3. Configura webhook no GitHub apontando para o servidor
  4. A partir daí: push em main → GitHub avisa servidor → servidor baixa e extrai o código

Uso:
  python scripts/setup-auto-deploy.py
"""

import ftplib
import json
import os
import secrets
import sys
import urllib.request
import urllib.error
import getpass

# ── Configuração ──────────────────────────────────────────────────────────────

REPOS = [
    {
        "repo":       "fredmourao-ai/site-shopvivaliz",
        "webhook_url": "https://dev.shopvivaliz.com.br/deploy-webhook.php",
        "ftp_remote":  "deploy-webhook.php",  # relativo a FTP_REMOTE_DIR
    },
]

WEBHOOK_PHP = os.path.join(os.path.dirname(__file__), "..", "deploy-webhook.php")
CREDS_FILE  = os.path.join(os.path.dirname(__file__), ".ftp-credentials")

# ── Helpers ───────────────────────────────────────────────────────────────────

def load_env(path: str) -> dict:
    env = {}
    if not os.path.isfile(path):
        return env
    for line in open(path, encoding="utf-8"):
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip().strip('"\'')
    return env

def save_creds(data: dict) -> None:
    with open(CREDS_FILE, "w", encoding="utf-8") as f:
        for k, v in data.items():
            f.write(f"{k}={v}\n")
    print(f"  Credenciais salvas em {CREDS_FILE} (gitignored)")

def ask(prompt: str, default: str = "", secret: bool = False) -> str:
    if secret:
        val = getpass.getpass(f"{prompt}: ")
    else:
        disp = f" [{default}]" if default else ""
        val  = input(f"{prompt}{disp}: ").strip()
    return val if val else default

def github_api(path: str, method: str = "GET", body: dict = None, token: str = "") -> dict:
    url = f"https://api.github.com{path}"
    data = json.dumps(body).encode() if body else None
    req  = urllib.request.Request(
        url, data=data, method=method,
        headers={
            "Authorization":        f"Bearer {token}",
            "Accept":               "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "Content-Type":         "application/json",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body_err = e.read().decode()
        print(f"  GitHub API erro {e.code}: {body_err[:200]}")
        return {"error": e.code}

def ftp_upload_text(ftp: ftplib.FTP, remote_path: str, content: str) -> None:
    import io
    ftp.storbinary(f"STOR {remote_path}", io.BytesIO(content.encode("utf-8")))

def ftp_download_text(ftp: ftplib.FTP, remote_path: str) -> str:
    import io
    buf = io.BytesIO()
    try:
        ftp.retrbinary(f"RETR {remote_path}", buf.write)
        return buf.getvalue().decode("utf-8", errors="replace")
    except ftplib.error_perm:
        return ""

# ── Coleta de credenciais ─────────────────────────────────────────────────────

def collect_credentials() -> dict:
    saved = load_env(CREDS_FILE)
    local = load_env(os.path.join(os.path.dirname(__file__), "..", ".env"))

    print("\n=== Credenciais FTP (HostGator) ===")
    print("(Encontre em cPanel → FTP Accounts, ou em sua planilha de senhas)\n")

    creds = {
        "FTP_SERVER":     ask("FTP_SERVER (ex: ftp.shopvivaliz.com.br)",
                              saved.get("FTP_SERVER", local.get("FTP_SERVER", ""))),
        "FTP_USERNAME":   ask("FTP_USERNAME",
                              saved.get("FTP_USERNAME", local.get("FTP_USERNAME", ""))),
        "FTP_PASSWORD":   ask("FTP_PASSWORD", secret=True),
        "FTP_PORT":       ask("FTP_PORT", saved.get("FTP_PORT", local.get("FTP_PORT", "21"))),
        "FTP_REMOTE_DIR": ask("FTP_REMOTE_DIR (raiz do site, ex: /public_html/dev)",
                              saved.get("FTP_REMOTE_DIR", local.get("FTP_REMOTE_DIR", "/"))),
        "GITHUB_TOKEN":   ask("GitHub Personal Access Token (repo:read + admin:repo_hook)",
                              saved.get("GITHUB_TOKEN", ""), secret=True),
    }
    if not creds["FTP_PASSWORD"]:
        creds["FTP_PASSWORD"] = saved.get("FTP_PASSWORD", "")

    save_creds(creds)
    return creds

# ── Passos do setup ───────────────────────────────────────────────────────────

def step_test_ftp(creds: dict) -> ftplib.FTP:
    print("\n[1/4] Testando conexão FTP...")
    ftp = ftplib.FTP()
    ftp.connect(creds["FTP_SERVER"], int(creds["FTP_PORT"]), timeout=30)
    ftp.login(creds["FTP_USERNAME"], creds["FTP_PASSWORD"])
    ftp.set_pasv(True)
    remote_dir = creds["FTP_REMOTE_DIR"].rstrip("/") or "/"
    ftp.cwd(remote_dir)
    print(f"  ✓ Conectado a {creds['FTP_SERVER']}{remote_dir}")
    return ftp

def step_upload_webhook(ftp: ftplib.FTP, creds: dict) -> str:
    print("\n[2/4] Enviando deploy-webhook.php para o servidor...")
    if not os.path.isfile(WEBHOOK_PHP):
        print(f"  ✗ Arquivo não encontrado: {WEBHOOK_PHP}")
        sys.exit(1)

    with open(WEBHOOK_PHP, "rb") as f:
        content = f.read()
    ftp.storbinary("STOR deploy-webhook.php", __import__("io").BytesIO(content))
    print("  ✓ deploy-webhook.php enviado")

    # Gerar DEPLOY_SECRET
    deploy_secret = secrets.token_hex(32)
    print(f"  → DEPLOY_SECRET gerado: {deploy_secret[:8]}…")

    # Atualizar .env no servidor (preserva linhas existentes)
    env_txt = ftp_download_text(ftp, ".env")
    lines = [l for l in env_txt.splitlines()
             if not l.strip().startswith("DEPLOY_SECRET=")
             and not l.strip().startswith("GITHUB_TOKEN=")]
    lines.append(f"DEPLOY_SECRET={deploy_secret}")
    lines.append(f"GITHUB_TOKEN={creds['GITHUB_TOKEN']}")
    new_env = "\n".join(lines).strip() + "\n"
    ftp_upload_text(ftp, ".env", new_env)
    print("  ✓ .env do servidor atualizado com DEPLOY_SECRET e GITHUB_TOKEN")

    return deploy_secret

def step_configure_webhooks(creds: dict, deploy_secret: str) -> None:
    print("\n[3/4] Configurando webhooks no GitHub...")
    token = creds["GITHUB_TOKEN"]
    if not token:
        print("  ✗ GITHUB_TOKEN não fornecido. Crie manualmente o webhook.")
        return

    for repo_cfg in REPOS:
        repo = repo_cfg["repo"]
        url  = repo_cfg["webhook_url"]
        print(f"\n  Repo: {repo}")

        # Listar webhooks existentes
        existing = github_api(f"/repos/{repo}/hooks", token=token)
        if isinstance(existing, list):
            for wh in existing:
                if wh.get("config", {}).get("url") == url:
                    github_api(f"/repos/{repo}/hooks/{wh['id']}", method="DELETE", token=token)
                    print(f"    → Webhook antigo removido (id={wh['id']})")

        # Criar webhook
        resp = github_api(f"/repos/{repo}/hooks", method="POST", token=token, body={
            "name":   "web",
            "active": True,
            "events": ["push"],
            "config": {
                "url":          url,
                "content_type": "json",
                "secret":       deploy_secret,
                "insecure_ssl": "0",
            },
        })
        if "id" in resp:
            print(f"    ✓ Webhook criado → {url} (id={resp['id']})")
        else:
            print(f"    ✗ Falha ao criar webhook: {resp}")

def step_test_webhook(creds: dict) -> None:
    print("\n[4/4] Testando se deploy-webhook.php está acessível...")
    for repo_cfg in REPOS:
        url = repo_cfg["webhook_url"]
        try:
            req = urllib.request.Request(url, method="GET",
                                         headers={"User-Agent": "ShopVivaliz-Setup/1.0"})
            with urllib.request.urlopen(req, timeout=10) as r:
                body = r.read().decode()
                if '"error"' in body and "Método não permitido" in body:
                    print(f"  ✓ {url} bloqueia GET sem assinatura (comportamento correto)")
                elif '"ok"' in body:
                    print(f"  ✓ {url} responde OK")
                else:
                    print(f"  ? {url} → {body[:100]}")
        except urllib.error.HTTPError as e:
            if e.code in (401, 403, 405):
                print(f"  ✓ {url} responde {e.code} (proteção ativa = OK)")
            else:
                print(f"  ? {url} → HTTP {e.code}")
        except Exception as e:
            print(f"  ✗ {url} não acessível: {e}")

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    print("=" * 60)
    print("  ShopVivaliz — Setup Deploy Autônomo")
    print("  Sem GitHub Actions, sem custo")
    print("=" * 60)

    creds = collect_credentials()

    try:
        ftp            = step_test_ftp(creds)
        deploy_secret  = step_upload_webhook(ftp, creds)
        ftp.quit()
    except Exception as e:
        print(f"\n✗ Erro FTP: {e}")
        sys.exit(1)

    step_configure_webhooks(creds, deploy_secret)
    step_test_webhook(creds)

    print("\n" + "=" * 60)
    print("  ✓ Setup concluído!")
    print()
    print("  Como funciona agora:")
    print("    git push → GitHub avisa o servidor → servidor baixa")
    print("    e extrai o código automaticamente.")
    print()
    print("  Para testar: faça qualquer commit e push em main.")
    print("=" * 60)

if __name__ == "__main__":
    main()
