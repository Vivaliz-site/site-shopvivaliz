#!/usr/bin/env python3
"""
ShopVivaliz - Centralizador de Secrets e Configuração
=====================================================

Fonte canônica Python para carregar, normalizar, mascarar e validar secrets.

Regras:
- Não registrar valores reais em logs.
- Código novo deve usar nomes canônicos definidos em docs/knowledge/secrets-and-integrations-map.md.
- Aliases legados ficam aqui apenas para compatibilidade.
"""

from __future__ import annotations

import json
import logging
import os
import sys
from pathlib import Path
from typing import Any

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


def first_env(*names: str, default: str = "") -> str:
    """Return the first non-empty environment value from a list of aliases."""
    for name in names:
        value = os.getenv(name)
        if value is not None and value.strip() != "":
            return value.strip()
    return default


def env_int(*names: str, default: int) -> int:
    value = first_env(*names, default=str(default))
    try:
        return int(value)
    except ValueError:
        return default


def env_bool(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().lower() in {"true", "1", "yes", "sim", "on"}


def load_env_file(env_path: Path) -> dict[str, str]:
    """Load key=value pairs from a .env file without external dependencies."""
    env_vars: dict[str, str] = {}
    if not env_path.exists():
        return env_vars

    try:
        with env_path.open("r", encoding="utf-8") as handle:
            for raw_line in handle:
                line = raw_line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                key = key.strip()
                value = value.strip()
                if (value.startswith('"') and value.endswith('"')) or (
                    value.startswith("'") and value.endswith("'")
                ):
                    value = value[1:-1]
                if key:
                    env_vars[key] = value
    except Exception as exc:  # pragma: no cover - defensive startup helper
        logger.warning("Erro ao carregar %s: %s", env_path, exc)

    return env_vars


for env_file in (Path(".env.local"), Path(".env")):
    for key, value in load_env_file(env_file).items():
        os.environ.setdefault(key, value)


def mask_secret(value: str, show_chars: int = 4) -> str:
    """Mask a secret before logging."""
    if not value:
        return "***"
    if len(value) <= show_chars:
        return "***"
    return value[:show_chars] + "*" * (len(value) - show_chars)


GEMINI_API_KEY = first_env("GEMINI_API_KEY")
ANTHROPIC_API_KEY = first_env("ANTHROPIC_API_KEY")
OPENAI_API_KEY = first_env("OPENAI_API_KEY")

SHOPEE_PARTNER_ID = first_env("SHOPEE_PARTNER_ID")
SHOPEE_PARTNER_KEY = first_env("SHOPEE_PARTNER_KEY")
SHOPEE_SHOP_ID = first_env("SHOPEE_SHOP_ID")
SHOPEE_ACCESS_TOKEN = first_env("SHOPEE_ACCESS_TOKEN")
SHOPEE_REFRESH_TOKEN = first_env("SHOPEE_REFRESH_TOKEN")
SHOPEE_API_BASE_URL = first_env("SHOPEE_API_BASE_URL", "SHOPEE_BASE_URL", default="https://partner.shopeemobile.com/api/v2")
SHOPEE_BASE_URL = SHOPEE_API_BASE_URL
SHOPEE_REDIRECT_URI = first_env("SHOPEE_REDIRECT_URI", default="https://shopvivaliz.com.br/")
SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS = env_int("SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS", default=7200)
SHOPEE_TEST_PARTNER_ID = first_env("SHOPEE_TEST_PARTNER_ID")
SHOPEE_TEST_PARTNER_KEY = first_env("SHOPEE_TEST_PARTNER_KEY")

AMAZON_LWA_CLIENT_ID = first_env("AMAZON_LWA_CLIENT_ID")
AMAZON_LWA_CLIENT_SECRET = first_env("AMAZON_LWA_CLIENT_SECRET")
AMAZON_LWA_REFRESH_TOKEN = first_env("AMAZON_LWA_REFRESH_TOKEN")
AMAZON_LWA_ACCESS_TOKEN = first_env("AMAZON_LWA_ACCESS_TOKEN")
AMAZON_AWS_ACCESS_KEY_ID = first_env("AMAZON_AWS_ACCESS_KEY_ID")
AMAZON_AWS_SECRET_ACCESS_KEY = first_env("AMAZON_AWS_SECRET_ACCESS_KEY")
AMAZON_AWS_ROLE_ARN = first_env("AMAZON_AWS_ROLE_ARN")
AMAZON_SP_API_REGION = first_env("AMAZON_SP_API_REGION", default="us-east-1")
AMAZON_SP_API_ENDPOINT = first_env("AMAZON_SP_API_ENDPOINT", default="https://sellingpartnerapi-na.amazon.com")
AMAZON_ACCOUNT_ID = first_env("AMAZON_ACCOUNT_ID")

# OLIST_* é canônico para o ecossistema Olist/ERP Marketplace.
OLIST_API_KEY = first_env("OLIST_API_KEY")
OLIST_SECRET = first_env("OLIST_SECRET")
OLIST_CLIENT_ID = first_env("OLIST_CLIENT_ID", "CLIENT_ID_API_OLIST")
OLIST_CLIENT_SECRET = first_env("OLIST_CLIENT_SECRET", "CLIENT_SECRET_OLIST")
OLIST_ACCESS_TOKEN = first_env("OLIST_ACCESS_TOKEN")
OLIST_REFRESH_TOKEN = first_env("OLIST_REFRESH_TOKEN")
OLIST_REDIRECT_URI = first_env("OLIST_REDIRECT_URI", "URL_REDIRCT_OLIST")
OLIST_API_BASE_URL = first_env("OLIST_API_BASE_URL", "URL_TINY_OLIST")
OLIST_EMAIL = first_env("OLIST_EMAIL")
OLIST_PASSWORD = first_env("OLIST_PASSWORD")

# Aliases legados Olist. Não usar em código novo.
CLIENT_ID_API_OLIST = OLIST_CLIENT_ID
CLIENT_SECRET_OLIST = OLIST_CLIENT_SECRET
URL_REDIRCT_OLIST = OLIST_REDIRECT_URI
URL_TINY_OLIST = OLIST_API_BASE_URL

# Tiny nativo: usar apenas quando o fluxo chama API Tiny diretamente.
TINY_CLIENT_ID = first_env("TINY_CLIENT_ID")
TINY_CLIENT_SECRET = first_env("TINY_CLIENT_SECRET")
TINY_ACCESS_TOKEN = first_env("TINY_ACCESS_TOKEN")
TINY_REFRESH_TOKEN = first_env("TINY_REFRESH_TOKEN")
TINY_API_BASE_URL = first_env("TINY_API_BASE_URL")

ML_CLIENT_ID = first_env("ML_CLIENT_ID")
ML_CLIENT_SECRET = first_env("ML_CLIENT_SECRET")
ML_REDIRECT_URI = first_env("ML_REDIRECT_URI")
ML_SELLER_ID = first_env("ML_SELLER_ID")
ML_ACCESS_TOKEN = first_env("ML_ACCESS_TOKEN")
ML_REFRESH_TOKEN = first_env("ML_REFRESH_TOKEN")
ML_SHOPVIVALIZ_API_URL = first_env("ML_SHOPVIVALIZ_API_URL")
ML_WEBHOOK_URL = first_env("ML_WEBHOOK_URL")

TIKTOK_SERVICE_ID = first_env("TIKTOK_SERVICE_ID")
TIKTOK_APP_KEY = first_env("TIKTOK_APP_KEY")
TIKTOK_APP_SECRET = first_env("TIKTOK_APP_SECRET")
TIKTOK_REDIRECT_URL = first_env("TIKTOK_REDIRECT_URL", default="https://shopvivaliz.com.br")
TIKTOK_AUTH_REGION = first_env("TIKTOK_AUTH_REGION", default="row")
TIKTOK_ACCESS_TOKEN = first_env("TIKTOK_ACCESS_TOKEN")
TIKTOK_REFRESH_TOKEN = first_env("TIKTOK_REFRESH_TOKEN")
TIKTOK_SHOP_CIPHER = first_env("TIKTOK_SHOP_CIPHER")
TIKTOK_SHOP_ID = first_env("TIKTOK_SHOP_ID")

FTP_SERVER = first_env("FTP_SERVER", "FTP_HOST")
FTP_USERNAME = first_env("FTP_USERNAME", "FTP_USER")
FTP_PASSWORD = first_env("FTP_PASSWORD", "FTP_PASS")
FTP_PORT = env_int("FTP_PORT", default=21)
FTP_REMOTE_DIR = first_env("FTP_REMOTE_DIR", "FTP_REMOTE_PATH", default="/public_html")
FTP_HOST = FTP_SERVER
FTP_USER = FTP_USERNAME
FTP_PASS = FTP_PASSWORD
FTP_REMOTE_PATH = FTP_REMOTE_DIR

SMTP_HOST = first_env("SMTP_HOST", "EMAIL_SMTP_HOST", "MAIL_HOST", default="smtp.titan.email")
SMTP_PORT = env_int("SMTP_PORT", "EMAIL_SMTP_PORT", "MAIL_PORT", default=465)
SMTP_USER = first_env("SMTP_USER", "EMAIL_USER", "MAIL_USER", default="agentes@shopvivaliz.com.br")
SMTP_PASS = first_env("SMTP_PASS", "EMAIL_PASSWORD", "MAIL_PASS")
EMAIL_FROM = first_env("EMAIL_FROM", default=SMTP_USER)
EMAIL_TO = first_env("EMAIL_TO")
MAIL_HOST = SMTP_HOST
MAIL_PORT = SMTP_PORT
MAIL_USER = SMTP_USER
MAIL_PASS = SMTP_PASS
EMAIL_SMTP_HOST = SMTP_HOST
EMAIL_SMTP_PORT = SMTP_PORT
EMAIL_USER = SMTP_USER
EMAIL_PASSWORD = SMTP_PASS

MELHORENVIO_ACCESS_TOKEN = first_env("MELHORENVIO_ACCESS_TOKEN", "MELHORENVIO_API_KEY")
MELHORENVIO_API_KEY = MELHORENVIO_ACCESS_TOKEN
MELHORENVIO_FROM_POSTAL_CODE = first_env("MELHORENVIO_FROM_POSTAL_CODE", default="35501236")

SESSION_SECRET = first_env("SESSION_SECRET")
CSRF_TOKEN_NAME = first_env("CSRF_TOKEN_NAME", default="csrf_token")
JWT_SECRET = first_env("JWT_SECRET")
APP_ENV = first_env("APP_ENV", default="development")
APP_DEBUG = env_bool("APP_DEBUG", default=False)
APP_URL = first_env("APP_URL", default="https://shopvivaliz.com.br")

DB_HOST = first_env("DB_HOST", default="localhost")
DB_PORT = env_int("DB_PORT", default=3306)
DB_NAME = first_env("DB_NAME", "DB_DATABASE", default="shopvivaliz")
DB_USER = first_env("DB_USER", "DB_USERNAME", default="root")
DB_PASS = first_env("DB_PASS", "DB_PASSWORD")
DB_DATABASE = DB_NAME
DB_USERNAME = DB_USER
DB_PASSWORD = DB_PASS

AGENTS_ENABLED = env_bool("AGENTS_ENABLED", default=True)
AGENTS_CONCURRENT = env_int("AGENTS_CONCURRENT", default=3)
AGENTS_TIMEOUT = env_int("AGENTS_TIMEOUT", default=120)
AGENTS_RETRY = env_int("AGENTS_RETRY", default=3)
CACHE_DRIVER = first_env("CACHE_DRIVER", default="file")
CACHE_TTL = env_int("CACHE_TTL", default=3600)
LOG_LEVEL = first_env("LOG_LEVEL", default="debug")
LOG_PATH = first_env("LOG_PATH", default="./logs")
LOG_MAX_SIZE = env_int("LOG_MAX_SIZE", default=10485760)

REQUIRED_SECRETS = {
    "ANTHROPIC_API_KEY": "Claude API",
    "SHOPEE_PARTNER_ID": "Shopee Partner ID",
    "SHOPEE_PARTNER_KEY": "Shopee Partner Key",
    "FTP_SERVER": "FTP Server",
    "FTP_USERNAME": "FTP Username",
    "FTP_PASSWORD": "FTP Password",
    "SMTP_PASS": "Email Password",
}

REQUIRED_SECRET_ALIASES = {
    "FTP_SERVER": ["FTP_SERVER", "FTP_HOST"],
    "FTP_USERNAME": ["FTP_USERNAME", "FTP_USER"],
    "FTP_PASSWORD": ["FTP_PASSWORD", "FTP_PASS"],
    "SMTP_PASS": ["SMTP_PASS", "EMAIL_PASSWORD", "MAIL_PASS"],
}


def get_all_secrets() -> dict[str, Any]:
    """Return masked secret/config inventory for diagnostics."""
    return {
        "GEMINI_API_KEY": mask_secret(GEMINI_API_KEY),
        "ANTHROPIC_API_KEY": mask_secret(ANTHROPIC_API_KEY),
        "OPENAI_API_KEY": mask_secret(OPENAI_API_KEY),
        "SHOPEE_PARTNER_ID": SHOPEE_PARTNER_ID[:4] if SHOPEE_PARTNER_ID else "***",
        "SHOPEE_PARTNER_KEY": mask_secret(SHOPEE_PARTNER_KEY),
        "SHOPEE_ACCESS_TOKEN": mask_secret(SHOPEE_ACCESS_TOKEN),
        "SHOPEE_REFRESH_TOKEN": mask_secret(SHOPEE_REFRESH_TOKEN),
        "SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS": SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS,
        "OLIST_CLIENT_ID": mask_secret(OLIST_CLIENT_ID),
        "OLIST_ACCESS_TOKEN": mask_secret(OLIST_ACCESS_TOKEN),
        "TINY_CLIENT_ID": mask_secret(TINY_CLIENT_ID),
        "TINY_ACCESS_TOKEN": mask_secret(TINY_ACCESS_TOKEN),
        "AMAZON_LWA_CLIENT_ID": mask_secret(AMAZON_LWA_CLIENT_ID),
        "AMAZON_AWS_ACCESS_KEY_ID": mask_secret(AMAZON_AWS_ACCESS_KEY_ID),
        "FTP_SERVER": FTP_SERVER,
        "FTP_USERNAME": FTP_USERNAME,
        "FTP_PASSWORD": mask_secret(FTP_PASSWORD),
        "FTP_PORT": FTP_PORT,
        "SMTP_HOST": SMTP_HOST,
        "SMTP_USER": SMTP_USER,
        "SMTP_PASS": mask_secret(SMTP_PASS),
        "SESSION_SECRET": mask_secret(SESSION_SECRET),
        "JWT_SECRET": mask_secret(JWT_SECRET),
        "APP_ENV": APP_ENV,
        "APP_DEBUG": APP_DEBUG,
        "DB_HOST": DB_HOST,
        "DB_NAME": DB_NAME,
        "DB_USER": DB_USER,
    }


def validate_secrets() -> tuple[bool, list[str]]:
    """Validate required secrets using canonical names and accepted aliases."""
    errors: list[str] = []
    for key, description in REQUIRED_SECRETS.items():
        value = ""
        for alias in REQUIRED_SECRET_ALIASES.get(key, [key]):
            value = str(globals().get(alias, "")).strip()
            if value:
                break
        if not value:
            errors.append(f"❌ {key} ({description}) - AUSENTE")
        else:
            logger.debug("✓ %s: %s", key, mask_secret(value))
    return len(errors) == 0, errors


def log_startup() -> None:
    """Log startup validation with masked values only."""
    success, errors = validate_secrets()
    if success:
        logger.info("✅ Todos os secrets obrigatórios carregados e validados com sucesso.")
        logger.debug("Secrets carregados: %s", get_all_secrets())
        return
    logger.error("❌ Erros na validação de secrets:")
    for error in errors:
        logger.error("   %s", error)
    sys.exit(1)


if __name__ == "__main__":
    logger.info("🔐 ShopVivaliz Secrets Centralizer")
    logger.info("=" * 50)
    log_startup()
    logger.info("Secrets carregados:")
    print(json.dumps(get_all_secrets(), indent=2, ensure_ascii=False))
