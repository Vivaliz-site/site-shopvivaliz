#!/usr/bin/env python3
"""
Setup GitHub Secrets para ShopVivaliz Pipeline
Adiciona todas as credenciais necessárias ao GitHub Actions
"""
import os
import subprocess
import json
import sys
from pathlib import Path
from typing import Dict, List

SECRETS_TO_ADD = {
    # API Keys
    'ANTHROPIC_API_KEY': 'Chave API Anthropic',
    'OPENAI_API_KEY': 'Chave API OpenAI',
    'GEMINI_API_KEY': 'Chave API Google Gemini',
    'GOOGLE_API_KEY': 'Chave API Google',

    # Olist
    'OLIST_CLIENT_ID': 'ID Cliente Olist',
    'OLIST_CLIENT_SECRET': 'Secret Cliente Olist',
    'OLIST_ACCESS_TOKEN': 'Token de acesso Olist',
    'OLIST_REFRESH_TOKEN': 'Refresh token Olist',
    'TOKEN_API_OLIST': 'Token API Olist',
    'CLIENT_ID_API_OLIST': 'ID API Olist',
    'CLIENT_SECRET_OLIST': 'Secret API Olist',
    'OLIST_REDIRECT_URI': 'Redirect Olist',

    # FTP
    'FTP_SERVER': 'Servidor FTP',
    'FTP_HOST': 'Servidor FTP (alias)',
    'FTP_USERNAME': 'Usuário FTP',
    'FTP_USER': 'Usuário FTP (alias)',
    'FTP_PASSWORD': 'Senha FTP',
    'FTP_PASS': 'Senha FTP (alias)',
    'FTP_PORT': '21 (porta FTP)',
    'FTP_REMOTE_DIR': '/public_html (diretório remoto)',

    # Email
    'EMAIL_FROM': 'Email remetente',
    'EMAIL_TO': 'Email destinatário',
    'EMAIL_SMTP_HOST': 'Host SMTP',
    'SMTP_HOST': 'Host SMTP (alias)',
    'EMAIL_SMTP_PORT': '587 (porta SMTP)',
    'SMTP_PORT': '587 (porta SMTP alias)',
    'EMAIL_USER': 'Usuário SMTP',
    'SMTP_USER': 'Usuário SMTP (alias)',
    'EMAIL_PASSWORD': 'Senha SMTP',
    'SMTP_PASS': 'Senha SMTP (alias)',

    # Database
    'DB_HOST': 'Host Database',
    'DB_NAME': 'Nome Database',
    'DB_DATABASE': 'Database completo',

    # Marketplace
    'SHOPEE_PARTNER_ID': 'ID Partner Shopee',
    'SHOPEE_PARTNER_KEY': 'Key Partner Shopee',
    'SHOPEE_TEST_PARTNER_ID': 'ID Partner Shopee Teste',
    'SHOPEE_TEST_PARTNER_KEY': 'Key Partner Shopee Teste',
    'TIKTOK_CLIENT_ID': 'ID Cliente TikTok Shop',
    'TIKTOK_CLIENT_SECRET': 'Secret TikTok Shop',
    'TIKTOK_APP_KEY': 'App Key TikTok',
    'TIKTOK_APP_SECRET': 'App Secret TikTok',

    # Tiny
    'TINY_CLIENT_ID': 'ID Cliente Tiny',
    'TINY_CLIENT_SECRET': 'Secret Cliente Tiny',
    'URL_TINY_OLIST': 'URL Tiny/Olist',
    'TINY_REDIRECT_URI': 'Redirect Tiny',
    'URL_REDIRECT_OLIST': 'URL Redirect Olist',
    'URL_REDIRCT_OLIST': 'URL Redirect Olist (legacy alias)',

    # Tokens
    'GH_REPO_TOKEN': 'Token GitHub',
    'SQUAD_TOKEN': 'Token Squad',
    'EMAIL_AGENTES_SECRET': 'Secret Agentes Email',
}

def check_gh_installed() -> bool:
    """Verifica se gh CLI está instalado"""
    try:
        subprocess.run(['gh', '--version'], capture_output=True, check=True)
        return True
    except FileNotFoundError:
        return False

def load_env_file(path: Path) -> Dict[str, str]:
    """Carrega variáveis de um arquivo .env"""
    env_vars = {}
    if not path.exists():
        return env_vars

    with path.open('r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                key, value = line.split('=', 1)
                env_vars[key.strip()] = value.strip().strip('"\'')
    return env_vars

def get_secret_value(key: str, sources: List[Dict[str, str]]) -> str:
    """Obtém valor do secret de múltiplas fontes"""
    # Tenta: environment var → .env file → input do usuário
    if key in os.environ:
        return os.environ[key]

    for source in sources:
        if key in source:
            return source[key]

    return None

def add_secret(key: str, value: str, description: str = '') -> bool:
    """Adiciona um secret ao GitHub"""
    if not value:
        print(f"⚠️  {key:30} - FALTA VALOR")
        return False

    try:
        # Mascarar valor para não expor
        masked_value = value[:5] + '*' * (len(value) - 10) + value[-5:] if len(value) > 10 else '***'

        # Usar gh cli
        process = subprocess.Popen(
            ['gh', 'secret', 'set', key],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        stdout, stderr = process.communicate(input=value)

        if process.returncode == 0:
            print(f"✅ {key:30} - {masked_value} ({description})")
            return True
        else:
            print(f"❌ {key:30} - ERRO: {stderr.strip()}")
            return False

    except Exception as e:
        print(f"❌ {key:30} - ERRO: {str(e)}")
        return False

def main():
    print("""
╔════════════════════════════════════════════════════════════════╗
║        🔐 SETUP GITHUB SECRETS - SHOPVIVALIZ PIPELINE          ║
╚════════════════════════════════════════════════════════════════╝
""")

    # Verificar gh CLI
    if not check_gh_installed():
        print("❌ GitHub CLI (gh) não está instalado!")
        print("   Instale com: https://cli.github.com/")
        return 1

    print("✅ GitHub CLI detectado\n")

    # Carregar variáveis de múltiplas fontes
    sources = [
        load_env_file(Path('.env')),
        load_env_file(Path('.env.local')),
        load_env_file(Path('secrets-to-copy.txt')),
    ]

    # Coletar secrets
    print("📝 COLETANDO SECRETS...\n")

    secrets_found = 0
    secrets_missing = 0

    for key, description in sorted(SECRETS_TO_ADD.items()):
        value = get_secret_value(key, sources)
        if value and value != 'VALOR_AQUI':
            secrets_found += 1
        else:
            secrets_missing += 1

    print(f"Encontrados: {secrets_found} secrets")
    print(f"Faltando: {secrets_missing} secrets\n")

    # Adicionar secrets
    print("🚀 ADICIONANDO SECRETS AO GITHUB...\n")

    success = 0
    failed = 0

    for key, description in sorted(SECRETS_TO_ADD.items()):
        value = get_secret_value(key, sources)

        if add_secret(key, value, description):
            success += 1
        else:
            failed += 1

    # Resumo
    print(f"\n{'='*60}")
    print(f"📊 RESULTADO")
    print(f"{'='*60}")
    print(f"✅ Adicionados: {success}")
    print(f"❌ Falharam: {failed}")
    print(f"⚠️  Faltando: {secrets_missing}")
    print(f"{'='*60}\n")

    # Próximos passos
    if failed == 0 and secrets_missing < 5:
        print("🎉 SECRETS CONFIGURADOS COM SUCESSO!")
        print("\n📋 Próximos passos:")
        print("   1. Verificar: gh secret list")
        print("   2. Editar: gh secret set CHAVE")
        print("   3. Testar: git push")
        return 0
    else:
        print("⚠️  ALGUNS SECRETS NÃO FORAM CONFIGURADOS")
        print("\n📋 Para adicionar manualmente:")
        print("   gh secret set CHAVE_DO_SECRET")
        return 1

if __name__ == '__main__':
    sys.exit(main())
