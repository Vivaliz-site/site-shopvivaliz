#!/usr/bin/env python3
"""
ShopVivaliz - Centralizador de Secrets e Configuração
=====================================================

Módulo único que carrega, valida e distribui TODOS os secrets da aplicação.
Consolida variáveis de: .env.local, .env, variáveis de ambiente.

Uso:
    from config.secrets import (
        ANTHROPIC_API_KEY, SHOPEE_API_KEY, FTP_PASSWORD,
        get_all_secrets, validate_secrets
    )

Validação:
    - Falha rápido se secrets obrigatórios estiverem ausentes
    - Logs mascarados (nunca expõe valores em logs)
    - Suporta fallbacks em cascata
"""

import os
import sys
from pathlib import Path
from typing import Dict, Any
import logging

# ============================================================================
# SETUP LOGGING
# ============================================================================
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


def first_env(*names: str, default: str = "") -> str:
    """Return the first non-empty environment value from a list of aliases."""
    for name in names:
        value = os.getenv(name)
        if value is not None and value.strip() != "":
            return value.strip()
    return default

# ============================================================================
# CARREGAMENTO MANUAL DE .env (SEM DEPENDÊNCIA)
# ============================================================================
def load_env_file(env_path: Path) -> Dict[str, str]:
    """Carrega variáveis de um arquivo .env manualmente."""
    env_vars = {}
    if not env_path.exists():
        return env_vars

    try:
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    key, value = line.split('=', 1)
                    key = key.strip()
                    value = value.strip()
                    # Remove quotes se existirem
                    if value.startswith('"') and value.endswith('"'):
                        value = value[1:-1]
                    elif value.startswith("'") and value.endswith("'"):
                        value = value[1:-1]
                    env_vars[key] = value
    except Exception as e:
        logger.warning(f"Erro ao carregar {env_path}: {e}")

    return env_vars

# Carregar .env files
ENV_FILES = [
    Path(".env.local"),      # Produção: secrets reais (nunca commitar)
    Path(".env"),            # Fallback: valores de exemplo
]

for env_file in ENV_FILES:
    if env_file.exists():
        env_vars = load_env_file(env_file)
        for key, value in env_vars.items():
            os.environ.setdefault(key, value)
        logger.debug(f"✓ Carregado: {env_file}")

# ============================================================================
# HELPER PARA MASCARAR VALORES EM LOGS
# ============================================================================
def mask_secret(value: str, show_chars: int = 4) -> str:
    """Mascarar secret para logs seguros."""
    if not value or len(value) <= show_chars:
        return "***"
    return value[:show_chars] + "*" * (len(value) - show_chars)

# ============================================================================
# SEÇÃO 1: APIs DE IA (Gemini, Claude, OpenAI)
# ============================================================================
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY", "").strip()
ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY", "").strip()
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "").strip()

# ============================================================================
# SEÇÃO 2: SHOPEE (E-commerce Integration)
# ============================================================================
SHOPEE_PARTNER_ID = os.getenv("SHOPEE_PARTNER_ID", "").strip()
SHOPEE_PARTNER_KEY = os.getenv("SHOPEE_PARTNER_KEY", "").strip()
SHOPEE_SHOP_ID = os.getenv("SHOPEE_SHOP_ID", "").strip()
SHOPEE_ACCESS_TOKEN = os.getenv("SHOPEE_ACCESS_TOKEN", "").strip()
SHOPEE_REFRESH_TOKEN = os.getenv("SHOPEE_REFRESH_TOKEN", "").strip()
SHOPEE_API_BASE_URL = os.getenv("SHOPEE_API_BASE_URL", "https://partner.shopeemobile.com")
SHOPEE_REDIRECT_URI = os.getenv("SHOPEE_REDIRECT_URI", "https://dev.shopvivaliz.com.br/")

# Shopee Test Environment
SHOPEE_TEST_PARTNER_ID = os.getenv("SHOPEE_TEST_PARTNER_ID", "").strip()
SHOPEE_TEST_PARTNER_KEY = os.getenv("SHOPEE_TEST_PARTNER_KEY", "").strip()

# ============================================================================
# SEÇÃO 3: AMAZON (E-commerce Integration)
# ============================================================================
AMAZON_LWA_CLIENT_ID = os.getenv("AMAZON_LWA_CLIENT_ID", "").strip()
AMAZON_LWA_CLIENT_SECRET = os.getenv("AMAZON_LWA_CLIENT_SECRET", "").strip()
AMAZON_LWA_REFRESH_TOKEN = os.getenv("AMAZON_LWA_REFRESH_TOKEN", "").strip()
AMAZON_LWA_ACCESS_TOKEN = os.getenv("AMAZON_LWA_ACCESS_TOKEN", "").strip()
AMAZON_AWS_ACCESS_KEY_ID = os.getenv("AMAZON_AWS_ACCESS_KEY_ID", "").strip()
AMAZON_AWS_SECRET_ACCESS_KEY = os.getenv("AMAZON_AWS_SECRET_ACCESS_KEY", "").strip()
AMAZON_AWS_ROLE_ARN = os.getenv("AMAZON_AWS_ROLE_ARN", "").strip()
AMAZON_SP_API_REGION = os.getenv("AMAZON_SP_API_REGION", "us-east-1")
AMAZON_SP_API_ENDPOINT = os.getenv("AMAZON_SP_API_ENDPOINT", "https://sellingpartnerapi-na.amazon.com")

# ============================================================================
# SEÇÃO 4: OLIST (E-commerce Integration)
# ============================================================================
OLIST_API_KEY = os.getenv("OLIST_API_KEY", "").strip()
OLIST_SECRET = os.getenv("OLIST_SECRET", "").strip()
OLIST_CLIENT_ID = os.getenv("OLIST_CLIENT_ID", "").strip()
OLIST_CLIENT_SECRET = os.getenv("OLIST_CLIENT_SECRET", "").strip()
OLIST_ACCESS_TOKEN = os.getenv("OLIST_ACCESS_TOKEN", "").strip()
OLIST_REFRESH_TOKEN = os.getenv("OLIST_REFRESH_TOKEN", "").strip()
TOKEN_API_OLIST = os.getenv("TOKEN_API_OLIST", "").strip()
CLIENT_ID_API_OLIST = os.getenv("CLIENT_ID_API_OLIST", "").strip()
CLIENT_SECRET_OLIST = os.getenv("CLIENT_SECRET_OLIST", "").strip()
OLIST_REDIRECT_URI = os.getenv("OLIST_REDIRECT_URI", "").strip()
OLIST_EMAIL = os.getenv("OLIST_EMAIL", "").strip()
OLIST_PASSWORD = os.getenv("OLIST_PASSWORD", "").strip()

# ============================================================================
# SEÇÃO 5: TIKTOK (E-commerce Integration)
# ============================================================================
TIKTOK_SERVICE_ID = os.getenv("TIKTOK_SERVICE_ID", "").strip()
TIKTOK_APP_KEY = os.getenv("TIKTOK_APP_KEY", "").strip()
TIKTOK_APP_SECRET = os.getenv("TIKTOK_APP_SECRET", "").strip()
TIKTOK_REDIRECT_URL = os.getenv("TIKTOK_REDIRECT_URL", "https://dev.shopvivaliz.com.br")
TIKTOK_AUTH_REGION = os.getenv("TIKTOK_AUTH_REGION", "row")
TIKTOK_ACCESS_TOKEN = os.getenv("TIKTOK_ACCESS_TOKEN", "").strip()
TIKTOK_REFRESH_TOKEN = os.getenv("TIKTOK_REFRESH_TOKEN", "").strip()
TIKTOK_SHOP_CIPHER = os.getenv("TIKTOK_SHOP_CIPHER", "").strip()
TIKTOK_SHOP_ID = os.getenv("TIKTOK_SHOP_ID", "").strip()

# ============================================================================
# SEÇÃO 6: FTP (Deploy e Sincronização)
# ============================================================================
FTP_SERVER = first_env("FTP_SERVER", "FTP_HOST")
FTP_HOST = first_env("FTP_HOST", "FTP_SERVER")
FTP_USERNAME = first_env("FTP_USERNAME", "FTP_USER")
FTP_USER = first_env("FTP_USER", "FTP_USERNAME")
FTP_PASSWORD = first_env("FTP_PASSWORD", "FTP_PASS")
FTP_PASS = first_env("FTP_PASS", "FTP_PASSWORD")
FTP_PORT = int(os.getenv("FTP_PORT", "21"))
FTP_REMOTE_DIR = os.getenv("FTP_REMOTE_DIR", "/public_html")

# ============================================================================
# SEÇÃO 7: EMAIL (SMTP - Titan Email)
# ============================================================================
MAIL_HOST = first_env("SMTP_HOST", "EMAIL_SMTP_HOST", "MAIL_HOST", default="smtp.titan.email")
MAIL_PORT = int(first_env("SMTP_PORT", "EMAIL_SMTP_PORT", "MAIL_PORT", default="465"))
MAIL_USER = first_env("SMTP_USER", "EMAIL_USER", "MAIL_USER", default="agentes@shopvivaliz.com.br")
MAIL_PASS = first_env("SMTP_PASS", "EMAIL_PASSWORD", "MAIL_PASS")
EMAIL_FROM = os.getenv("EMAIL_FROM", MAIL_USER)
EMAIL_TO = os.getenv("EMAIL_TO", "").strip()

# Aliases (para compatibilidade com código antigo)
SMTP_HOST = os.getenv("SMTP_HOST", MAIL_HOST)
SMTP_PORT = int(os.getenv("SMTP_PORT", str(MAIL_PORT)))
SMTP_USER = os.getenv("SMTP_USER", MAIL_USER)
SMTP_PASS = os.getenv("SMTP_PASS", MAIL_PASS)
EMAIL_SMTP_HOST = os.getenv("EMAIL_SMTP_HOST", MAIL_HOST)
EMAIL_SMTP_PORT = int(os.getenv("EMAIL_SMTP_PORT", str(MAIL_PORT)))
EMAIL_USER = os.getenv("EMAIL_USER", MAIL_USER)
EMAIL_PASSWORD = os.getenv("EMAIL_PASSWORD", SMTP_PASS or MAIL_PASS)

# ============================================================================
# SEÇÃO 8: PAGAMENTO (Pagar.me)
# ============================================================================
PAGARME_SECRET_KEY = os.getenv("PAGARME_SECRET_KEY", "").strip()
PAGARME_API_KEY = os.getenv("PAGARME_API_KEY", "").strip()
PAGARME_PUBLIC_KEY = os.getenv("PAGARME_PUBLIC_KEY", "").strip()

# ============================================================================
# SEÇÃO 9: ENVIOS (Melhor Envio)
# ============================================================================
MELHORENVIO_ACCESS_TOKEN = os.getenv("MELHORENVIO_ACCESS_TOKEN", "").strip()
MELHORENVIO_API_KEY = os.getenv("MELHORENVIO_API_KEY", "").strip()
MELHORENVIO_FROM_POSTAL_CODE = os.getenv("MELHORENVIO_FROM_POSTAL_CODE", "35501236")

# ============================================================================
# SEÇÃO 10: SEGURANÇA E TOKENS
# ============================================================================
SESSION_SECRET = os.getenv("SESSION_SECRET", "").strip()
CSRF_TOKEN_NAME = os.getenv("CSRF_TOKEN_NAME", "csrf_token")
JWT_SECRET = os.getenv("JWT_SECRET", "").strip()

# ============================================================================
# SEÇÃO 11: AMBIENTE E CONFIGURAÇÃO
# ============================================================================
APP_ENV = os.getenv("APP_ENV", "development")
APP_DEBUG = os.getenv("APP_DEBUG", "false").lower() in ("true", "1", "yes")
APP_URL = os.getenv("APP_URL", "https://dev.shopvivaliz.com.br")

# Database (se necessário para scripts)
DB_HOST = os.getenv("DB_HOST", "localhost")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_NAME = os.getenv("DB_NAME", "shopvivaliz")
DB_USER = os.getenv("DB_USER", "root")
DB_PASS = os.getenv("DB_PASS", "").strip()

# ============================================================================
# SEÇÃO 12: AGENTES IA
# ============================================================================
AGENTS_ENABLED = os.getenv("AGENTS_ENABLED", "true").lower() in ("true", "1", "yes")
AGENTS_CONCURRENT = int(os.getenv("AGENTS_CONCURRENT", "3"))
AGENTS_TIMEOUT = int(os.getenv("AGENTS_TIMEOUT", "120"))
AGENTS_RETRY = int(os.getenv("AGENTS_RETRY", "3"))

# ============================================================================
# SEÇÃO 13: CACHE E LOGGING
# ============================================================================
CACHE_DRIVER = os.getenv("CACHE_DRIVER", "file")
CACHE_TTL = int(os.getenv("CACHE_TTL", "3600"))
LOG_LEVEL = os.getenv("LOG_LEVEL", "debug")
LOG_PATH = os.getenv("LOG_PATH", "./logs")
LOG_MAX_SIZE = int(os.getenv("LOG_MAX_SIZE", "10485760"))

# ============================================================================
# VALIDAÇÃO E DIAGNOSTICS
# ============================================================================

def get_all_secrets() -> Dict[str, Any]:
    """Retorna dicionário com TODOS os secrets (valores MASCARADOS para logs)."""
    return {
        # IA
        "GEMINI_API_KEY": mask_secret(GEMINI_API_KEY),
        "ANTHROPIC_API_KEY": mask_secret(ANTHROPIC_API_KEY),
        "OPENAI_API_KEY": mask_secret(OPENAI_API_KEY),

        # Shopee
        "SHOPEE_PARTNER_ID": SHOPEE_PARTNER_ID[:4] if SHOPEE_PARTNER_ID else "***",
        "SHOPEE_PARTNER_KEY": mask_secret(SHOPEE_PARTNER_KEY),
        "SHOPEE_ACCESS_TOKEN": mask_secret(SHOPEE_ACCESS_TOKEN),

        # Amazon
        "AMAZON_LWA_CLIENT_ID": mask_secret(AMAZON_LWA_CLIENT_ID),
        "AMAZON_AWS_ACCESS_KEY_ID": mask_secret(AMAZON_AWS_ACCESS_KEY_ID),

        # FTP
        "FTP_SERVER": FTP_SERVER,
        "FTP_USERNAME": FTP_USERNAME,
        "FTP_PASSWORD": mask_secret(FTP_PASSWORD),
        "FTP_PORT": FTP_PORT,

        # Email
        "MAIL_HOST": MAIL_HOST,
        "MAIL_USER": MAIL_USER,
        "MAIL_PASS": mask_secret(MAIL_PASS),

        # Segurança
        "SESSION_SECRET": mask_secret(SESSION_SECRET),
        "JWT_SECRET": mask_secret(JWT_SECRET),

        # Ambiente
        "APP_ENV": APP_ENV,
        "APP_DEBUG": APP_DEBUG,
    }

REQUIRED_SECRETS = {
    "ANTHROPIC_API_KEY": "Claude API",
    "SHOPEE_PARTNER_ID": "Shopee Partner ID",
    "SHOPEE_PARTNER_KEY": "Shopee Partner Key",
    "FTP_SERVER": "FTP Server",
    "FTP_USERNAME": "FTP Username",
    "FTP_PASSWORD": "FTP Password",
    "MAIL_PASS": "Email Password",
}

REQUIRED_SECRET_ALIASES = {
    "FTP_SERVER": ["FTP_SERVER", "FTP_HOST"],
    "FTP_USERNAME": ["FTP_USERNAME", "FTP_USER"],
    "FTP_PASSWORD": ["FTP_PASSWORD", "FTP_PASS"],
    "MAIL_PASS": ["SMTP_PASS", "EMAIL_PASSWORD", "MAIL_PASS"],
}

def validate_secrets() -> tuple[bool, list[str]]:
    """
    Valida se TODOS os secrets obrigatórios estão presentes.

    Retorna:
        (sucesso: bool, erros: list[str])
    """
    errors = []

    for key, description in REQUIRED_SECRETS.items():
        value = ""
        for alias in REQUIRED_SECRET_ALIASES.get(key, [key]):
            value = str(globals().get(alias, "")).strip()
            if value:
                break
        if not value:
            errors.append(f"❌ {key} ({description}) - AUSENTE")
        else:
            logger.debug(f"✓ {key}: {mask_secret(value)}")

    return len(errors) == 0, errors

def log_startup() -> None:
    """Log de inicialização com todos os secrets validados."""
    success, errors = validate_secrets()

    if success:
        logger.info("✅ Todos os secrets carregados e validados com sucesso!")
        logger.debug(f"Secrets carregados: {get_all_secrets()}")
    else:
        logger.error("❌ Erros na validação de secrets:")
        for error in errors:
            logger.error(f"   {error}")
        sys.exit(1)

# ============================================================================
# AUTO-VALIDAÇÃO NA IMPORTAÇÃO
# ============================================================================
# Descomente para validar automaticamente ao importar este módulo:
# log_startup()

if __name__ == "__main__":
    logger.info("🔐 ShopVivaliz Secrets Centralizer")
    logger.info("=" * 50)
    log_startup()
    logger.info("\nSecrets carregados:")
    import json
    print(json.dumps(get_all_secrets(), indent=2))
