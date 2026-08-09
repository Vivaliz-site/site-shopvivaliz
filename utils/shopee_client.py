"""Compatibility import for the canonical Shopee Open API client.

All token refresh, product update and image upload behavior lives in
``scripts.utils.shopee_client``. Keeping this module as a thin import prevents
older automations from silently using a stale client without token renewal.
"""
from scripts.utils.shopee_client import ShopeeClient

__all__ = ["ShopeeClient"]
