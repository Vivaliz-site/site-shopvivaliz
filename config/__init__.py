"""
ShopVivaliz Config Package
=========================

Centraliza configuração e secrets da aplicação.
"""

from .secrets import (
    # IA APIs
    GEMINI_API_KEY,
    ANTHROPIC_API_KEY,
    OPENAI_API_KEY,

    # Shopee
    SHOPEE_PARTNER_ID,
    SHOPEE_PARTNER_KEY,
    SHOPEE_SHOP_ID,
    SHOPEE_ACCESS_TOKEN,
    SHOPEE_REFRESH_TOKEN,
    SHOPEE_API_BASE_URL,
    SHOPEE_REDIRECT_URI,
    SHOPEE_TEST_PARTNER_ID,
    SHOPEE_TEST_PARTNER_KEY,

    # Amazon
    AMAZON_LWA_CLIENT_ID,
    AMAZON_LWA_CLIENT_SECRET,
    AMAZON_LWA_REFRESH_TOKEN,
    AMAZON_LWA_ACCESS_TOKEN,
    AMAZON_AWS_ACCESS_KEY_ID,
    AMAZON_AWS_SECRET_ACCESS_KEY,
    AMAZON_SP_API_REGION,
    AMAZON_SP_API_ENDPOINT,

    # Olist
    OLIST_API_KEY,
    OLIST_SECRET,
    OLIST_CLIENT_ID,
    OLIST_CLIENT_SECRET,
    OLIST_ACCESS_TOKEN,
    OLIST_REFRESH_TOKEN,
    OLIST_REDIRECT_URI,

    # TikTok
    TIKTOK_SERVICE_ID,
    TIKTOK_APP_KEY,
    TIKTOK_APP_SECRET,
    TIKTOK_REDIRECT_URL,
    TIKTOK_ACCESS_TOKEN,
    TIKTOK_REFRESH_TOKEN,
    TIKTOK_SHOP_ID,

    # FTP
    FTP_SERVER,
    FTP_HOST,
    FTP_USERNAME,
    FTP_USER,
    FTP_PASSWORD,
    FTP_PASS,
    FTP_PORT,
    FTP_REMOTE_DIR,

    # Email
    MAIL_HOST,
    MAIL_PORT,
    MAIL_USER,
    MAIL_PASS,
    EMAIL_FROM,
    EMAIL_TO,
    SMTP_HOST,
    SMTP_PORT,
    SMTP_USER,
    SMTP_PASS,

    # Payment
    PAGARME_SECRET_KEY,
    PAGARME_API_KEY,
    PAGARME_PUBLIC_KEY,

    # Shipping
    MELHORENVIO_ACCESS_TOKEN,
    MELHORENVIO_API_KEY,

    # Security
    SESSION_SECRET,
    JWT_SECRET,

    # App Config
    APP_ENV,
    APP_DEBUG,
    APP_URL,
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_USER,
    DB_PASS,

    # Functions
    get_all_secrets,
    validate_secrets,
    log_startup,
)

__all__ = [
    "GEMINI_API_KEY",
    "ANTHROPIC_API_KEY",
    "OPENAI_API_KEY",
    "SHOPEE_PARTNER_ID",
    "SHOPEE_PARTNER_KEY",
    "SHOPEE_SHOP_ID",
    "SHOPEE_ACCESS_TOKEN",
    "SHOPEE_REFRESH_TOKEN",
    "SHOPEE_API_BASE_URL",
    "SHOPEE_REDIRECT_URI",
    "SHOPEE_TEST_PARTNER_ID",
    "SHOPEE_TEST_PARTNER_KEY",
    "AMAZON_LWA_CLIENT_ID",
    "AMAZON_LWA_CLIENT_SECRET",
    "AMAZON_LWA_REFRESH_TOKEN",
    "AMAZON_LWA_ACCESS_TOKEN",
    "AMAZON_AWS_ACCESS_KEY_ID",
    "AMAZON_AWS_SECRET_ACCESS_KEY",
    "AMAZON_SP_API_REGION",
    "AMAZON_SP_API_ENDPOINT",
    "OLIST_API_KEY",
    "OLIST_SECRET",
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "OLIST_ACCESS_TOKEN",
    "OLIST_REFRESH_TOKEN",
    "OLIST_REDIRECT_URI",
    "TIKTOK_SERVICE_ID",
    "TIKTOK_APP_KEY",
    "TIKTOK_APP_SECRET",
    "TIKTOK_REDIRECT_URL",
    "TIKTOK_ACCESS_TOKEN",
    "TIKTOK_REFRESH_TOKEN",
    "TIKTOK_SHOP_ID",
    "FTP_SERVER",
    "FTP_HOST",
    "FTP_USERNAME",
    "FTP_USER",
    "FTP_PASSWORD",
    "FTP_PASS",
    "FTP_PORT",
    "FTP_REMOTE_DIR",
    "MAIL_HOST",
    "MAIL_PORT",
    "MAIL_USER",
    "MAIL_PASS",
    "EMAIL_FROM",
    "EMAIL_TO",
    "SMTP_HOST",
    "SMTP_PORT",
    "SMTP_USER",
    "SMTP_PASS",
    "PAGARME_SECRET_KEY",
    "PAGARME_API_KEY",
    "PAGARME_PUBLIC_KEY",
    "MELHORENVIO_ACCESS_TOKEN",
    "MELHORENVIO_API_KEY",
    "SESSION_SECRET",
    "JWT_SECRET",
    "APP_ENV",
    "APP_DEBUG",
    "APP_URL",
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "get_all_secrets",
    "validate_secrets",
    "log_startup",
]
