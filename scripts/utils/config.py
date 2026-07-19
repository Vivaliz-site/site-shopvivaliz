#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Configurações globais do sistema de automação
"""

import os
from pathlib import Path

# Paths
PROJECT_ROOT = Path(__file__).parent.parent.parent
SCRIPTS_DIR = PROJECT_ROOT / 'scripts'
STORAGE_DIR = PROJECT_ROOT / 'storage'
IA_IMAGES_DIR = STORAGE_DIR / 'ia_images'
LOGS_DIR = PROJECT_ROOT / 'logs'
PLANILHAS_DIR = PROJECT_ROOT / 'planilhas'

# Criar diretórios se não existirem
for dir_path in [STORAGE_DIR, IA_IMAGES_DIR, LOGS_DIR, PLANILHAS_DIR]:
    dir_path.mkdir(exist_ok=True)

# APIs
OPENAI_API_KEY = os.getenv('OPENAI_API_KEY', '')
SHOPEE_ACCESS_TOKEN = os.getenv('SHOPEE_ACCESS_TOKEN', '')
SHOPEE_SHOP_ID = os.getenv('SHOPEE_SHOP_ID', '')
TIKTOK_ACCESS_TOKEN = os.getenv('TIKTOK_ACCESS_TOKEN', '')
TIKTOK_SHOP_ID = os.getenv('TIKTOK_SHOP_ID', '')

# Configurações IA
IA_CONFIG = {
    'model': os.getenv('OPENAI_VISION_MODEL') or os.getenv('OPENAI_MODEL') or 'gpt-4o-mini',
    'images_per_product': 4,
    'image_quality': os.getenv('OPENAI_IMAGE_QUALITY') or 'standard',
    'timeout': 60,
}

# Configurações SEO
SEO_CONFIG = {
    'shopee': {
        'max_title': 120,
        'max_description': 500,
        'focus': 'keywords',
    },
    'tiktok': {
        'max_title': 150,
        'max_description': 2200,
        'focus': 'emotional',
    }
}

# Configurações A/B Test
ABTEST_CONFIG = {
    'min_impressions': 100,
    'min_duration_days': 7,
    'significance_level': 0.05,
}

# Configurações Priorização
PRIORITY_CONFIG = {
    'min_score': 30,
    'max_products_per_run': 50,
}

# Configurações Analytics
ANALYTICS_CONFIG = {
    'tracking_enabled': True,
    'log_format': 'json',
}

# Marketplace URLs
SHOPEE_API_BASE = 'https://openplatform.sandbox.test-stable.shopee.sg'
TIKTOK_API_BASE = 'https://open-api.tiktokglobalshop.com'

# Fallback
FALLBACK_IMAGE_URL = None
FALLBACK_TITLE = 'Produto em atualização'
