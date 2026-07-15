#!/usr/bin/env python3
"""
Configure branch protection para main branch no GitHub.
Permite que GitHub Actions faça push direto sem aprovação.
"""
import os
import sys
import requests

def configurar_branch_protection():
    token = os.getenv("GITHUB_TOKEN") or os.getenv("GH_TOKEN")
    if not token:
        print(" GITHUB_TOKEN não encontrado em variáveis de ambiente")
        sys.exit(1)

    # Extrair owner/repo do git remote
    repo_url = os.popen("git config --get remote.origin.url").read().strip()
    if "github.com" not in repo_url:
        print(f" Remote URL inválida: {repo_url}")
        sys.exit(1)

    # Extrair owner/repo
    if repo_url.startswith("git@"):
        parts = repo_url.split(":")[-1].replace(".git", "").split("/")
    else:
        parts = repo_url.rstrip(".git").split("/")[-2:]

    owner, repo = parts[0], parts[1]
    print(f"📦 Configurando {owner}/{repo}...")

    url = f"https://api.github.com/repos/{owner}/{repo}/branches/main/protection"

    headers = {
        "Authorization": f"token {token}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    }

    payload = {
        "required_status_checks": None,
        "enforce_admins": False,
        "required_pull_request_reviews": {
            "dismiss_stale_reviews": False,
            "require_code_owner_reviews": False,
            "required_approving_review_count": 0,
            "bypass_pull_request_allowances": {
                "users": [],
                "teams": [],
                "apps": ["GitHub Actions"],
            },
        },
        "restrictions": None,
        "required_linear_history": False,
        "allow_force_pushes": True,
        "allow_deletions": False,
    }

    response = requests.put(url, json=payload, headers=headers)

    if response.status_code in (200, 201):
        print(" Branch protection configurada com sucesso!")
        print("   - GitHub Actions pode fazer push direto")
        print("   - Force push habilitado para recuperação de emergência")
    else:
        print(f" Erro ao configurar: {response.status_code}")
        print(response.text)
        sys.exit(1)

if __name__ == "__main__":
    configurar_branch_protection()
