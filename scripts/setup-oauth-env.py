#!/usr/bin/env python3
"""
Script para configurar OAuth credentials no .env do ShopVivaliz
Uso: python3 setup-oauth-env.py
"""

import os
import sys
import re
from pathlib import Path

def prompt_for_value(name: str, required: bool = True, secret: bool = False) -> str:
    """Prompt do usuário para um valor"""
    prompt_text = f"  {name}: " if not secret else f"  {name} (oculto): "

    if secret:
        import getpass
        value = getpass.getpass(prompt_text)
    else:
        value = input(prompt_text).strip()

    if required and not value:
        print(f"    ❌ {name} é obrigatório!")
        return prompt_for_value(name, required, secret)

    return value

def read_env_file(path: str) -> dict:
    """Lê arquivo .env e retorna dict de variáveis"""
    env_vars = {}
    if not os.path.exists(path):
        return env_vars

    with open(path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                key, value = line.split('=', 1)
                env_vars[key.strip()] = value.strip()

    return env_vars

def write_env_file(path: str, env_vars: dict) -> None:
    """Escreve variáveis no arquivo .env"""
    with open(path, 'w', encoding='utf-8') as f:
        for key, value in sorted(env_vars.items()):
            if value.startswith('"') or value.startswith("'"):
                f.write(f"{key}={value}\n")
            else:
                f.write(f"{key}={value}\n")

def main():
    """Função principal"""
    print("\n" + "="*60)
    print("  🔐 Configurador de OAuth - ShopVivaliz")
    print("="*60 + "\n")

    # Determinar caminho do .env
    if len(sys.argv) > 1:
        env_path = sys.argv[1]
    else:
        env_path = "/home/ubuntu/site-shopvivaliz/.env"
        if not os.path.exists(env_path):
            env_path = os.path.expanduser("~/.shopvivaliz.env")

    print(f"📝 Arquivo .env: {env_path}\n")

    if not os.path.exists(os.path.dirname(env_path)):
        os.makedirs(os.path.dirname(env_path), exist_ok=True)

    env_vars = read_env_file(env_path)

    # Menu
    print("O que deseja configurar?")
    print("  1️⃣  Google OAuth 2.0")
    print("  2️⃣  Apple Sign In")
    print("  3️⃣  Ambos")
    print("  4️⃣  Sair\n")

    choice = input("Escolha (1-4): ").strip()

    if choice == "4":
        print("\n✅ Saindo sem fazer alterações.\n")
        return

    # Google OAuth
    if choice in ["1", "3"]:
        print("\n" + "─"*60)
        print("🔵 GOOGLE OAUTH 2.0")
        print("─"*60 + "\n")
        print("Obtenha suas credenciais em: https://console.cloud.google.com/\n")

        google_id = prompt_for_value("Google Client ID", required=True)
        google_secret = prompt_for_value("Google Client Secret", required=True, secret=True)

        env_vars['GOOGLE_OAUTH_CLIENT_ID'] = google_id
        env_vars['GOOGLE_OAUTH_CLIENT_SECRET'] = google_secret

        print("  ✅ Google OAuth configurado!\n")

    # Apple Sign In
    if choice in ["2", "3"]:
        print("\n" + "─"*60)
        print("🍎 APPLE SIGN IN")
        print("─"*60 + "\n")
        print("Obtenha suas credenciais em: https://developer.apple.com/account/\n")

        apple_id = prompt_for_value("Apple Service ID", required=True)
        apple_team = prompt_for_value("Apple Team ID", required=True)
        apple_key = prompt_for_value("Apple Key ID", required=True)

        print("\n  📝 Cole o conteúdo do arquivo .p8 (sua chave privada)")
        print("  (Deixe em branco quando terminar)\n")

        lines = []
        while True:
            line = input()
            if not line:
                break
            lines.append(line)

        apple_private_key = "\n".join(lines)

        if not apple_private_key.strip():
            print("  ❌ Chave privada não pode estar vazia!")
            return main()

        env_vars['APPLE_OAUTH_CLIENT_ID'] = apple_id
        env_vars['APPLE_TEAM_ID'] = apple_team
        env_vars['APPLE_KEY_ID'] = apple_key
        env_vars['APPLE_PRIVATE_KEY'] = f'"{apple_private_key}"'

        print("  ✅ Apple Sign In configurado!\n")

    # Salvar
    print("\n" + "="*60)
    print(f"💾 Salvando em: {env_path}")
    print("="*60 + "\n")

    try:
        write_env_file(env_path, env_vars)
        os.chmod(env_path, 0o600)  # Apenas leitura do proprietário

        print("✅ Arquivo .env atualizado com sucesso!\n")

        print("📋 Próximos passos:")
        print("  1. Restart do servidor (se necessário)")
        print("  2. Teste em: https://dev.shopvivaliz.com.br/auth/login.php")
        print("  3. Clique em 'Google' ou 'Apple' para testar\n")

    except Exception as e:
        print(f"❌ Erro ao salvar: {e}\n")
        return 1

    return 0

if __name__ == '__main__':
    sys.exit(main())
