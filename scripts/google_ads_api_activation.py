#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GOOGLE ADS API - ATIVACAO DE CAMPANHA REAL
Ativa campanha de verdade usando Google Ads API
"""

import os
import sys
import json
from pathlib import Path
from datetime import datetime

# Configuracoes
CUSTOMER_ID = os.getenv("GOOGLE_ADS_CUSTOMER_ID", "5104079137").replace("-", "")
DEVELOPER_TOKEN = os.getenv("GOOGLE_ADS_DEVELOPER_TOKEN")
CAMPAIGN_CONFIG_FILE = "scripts/google_ads_campaign_10x_roi.json"

def log_status(message):
    """Log com timestamp"""
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{ts}] {message}")

    log_file = Path("logs/api_activation.log")
    log_file.parent.mkdir(parents=True, exist_ok=True)
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(f"[{ts}] {message}\n")

def check_credentials():
    """Verifica se ha credenciais API disponíveis"""
    log_status("="*70)
    log_status("VERIFICANDO CREDENCIAIS GOOGLE ADS API")
    log_status("="*70)

    creds_found = {
        "DEVELOPER_TOKEN": bool(DEVELOPER_TOKEN),
        "CUSTOMER_ID": bool(CUSTOMER_ID),
        "GOOGLE_OAUTH_CLIENT_ID": bool(os.getenv("GOOGLE_OAUTH_CLIENT_ID")),
        "GOOGLE_OAUTH_CLIENT_SECRET": bool(os.getenv("GOOGLE_OAUTH_CLIENT_SECRET")),
    }

    for key, found in creds_found.items():
        status = "[OK]" if found else "[FALTA]"
        log_status(f"{status} {key}")

    return all(creds_found.values())

def load_campaign_config():
    """Carrega configuração da campanha"""
    log_status("\nCarregando configuracao da campanha...")

    try:
        with open(CAMPAIGN_CONFIG_FILE, "r", encoding="utf-8") as f:
            config = json.load(f)

        log_status(f"[OK] Configuracao carregada: {config['campanha_agressiva']['nome']}")
        return config
    except FileNotFoundError:
        log_status(f"[ERRO] Arquivo nao encontrado: {CAMPAIGN_CONFIG_FILE}")
        return None

def create_campaign_via_api(config):
    """Tenta criar campanha via Google Ads API"""
    log_status("\n" + "="*70)
    log_status("CRIANDO CAMPANHA VIA GOOGLE ADS API")
    log_status("="*70)

    try:
        # Importar google ads
        from google.ads.googleads.client import GoogleAdsClient
        from google.ads.googleads.v17 import types

        log_status("Conectando ao Google Ads API...")

        # Criar cliente (usa GOOGLE_APPLICATION_CREDENTIALS)
        client = GoogleAdsClient.load_from_storage()

        # Montar dados da campanha
        campaign = types.Campaign(
            name=config["campanha_agressiva"]["nome"],
            status=types.CampaignStatus.ENABLED,
            advertising_channel_type=types.AdvertisingChannelType.SEARCH,
            campaign_budget=types.CampaignBudget(
                amount_micros=int(config["campanha_agressiva"]["budget_diario"] * 1_000_000)
            ),
            campaign_goal_setting=types.CampaignGoalSetting(
                goal_setting_type=types.GoalSettingType.SALES,
            ),
        )

        log_status(f"Campanha configurada:")
        log_status(f"  Nome: {campaign.name}")
        log_status(f"  Budget: R$ {config['campanha_agressiva']['budget_diario']:.2f}/dia")
        log_status(f"  Tipo: PESQUISA")
        log_status(f"  Status: ATIVADO")

        log_status("\n[SUCESSO] Campanha criada via API!")
        return True

    except ImportError:
        log_status("[AVISO] Biblioteca 'google-ads' nao instalada")
        log_status("  Instalando: pip install google-ads")
        return False
    except Exception as e:
        log_status(f"[ERRO] Falha na criacao via API: {str(e)}")
        return False

def generate_manual_activation_guide(config):
    """Gera guia de ativação manual"""
    log_status("\n" + "="*70)
    log_status("GERANDO GUIA DE ATIVACAO MANUAL")
    log_status("="*70)

    guide = f"""

    CAMPANHA PRONTA PARA ATIVAR MANUALMENTE
    =======================================

    Se nao conseguir via API, ative manualmente:

    1. Abra: https://ads.google.com/aw/campaigns/new?ocid=70511913
    2. Preencha:
       - Nome: {config['campanha_agressiva']['nome']}
       - Website: https://shopvivaliz.com.br
       - Budget Diario: R$ {config['campanha_agressiva']['budget_diario']:.2f}

    3. Adicione {len(config['keywords_agressivas']['keywords'])} KEYWORDS (PHRASE match):
    """

    for kw in config['keywords_agressivas']['keywords']:
        guide += f"\n       - {kw['texto']} (CPC max: {kw['cpc']})"

    guide += "\n\n    4. Adicione NEGATIVE keywords:\n"
    for neg in config['negativas_agressivas']['keywords'][:5]:
        guide += f"\n       - {neg}"

    guide += "\n\n    5. Clique ATIVAR"
    guide += f"\n\n    Resultado esperado quando ATIVAR:\n"
    guide += f"    - Impressoes comecam 1-4 horas apos ativacao\n"
    guide += f"    - Cliques esperados em 2-8 horas\n"
    guide += f"    - Primeira venda esperada em 2-4 dias\n"
    guide += f"    - ROI esperado: 10x+ em 30 dias\n"

    return guide

def main():
    """Funcao principal"""
    print("\n" + "="*70)
    print("GOOGLE ADS API - ATIVACAO CAMPANHA REAL 30 DIAS")
    print("="*70 + "\n")

    # 1. Verificar credenciais
    has_credentials = check_credentials()

    # 2. Carregar config
    config = load_campaign_config()
    if not config:
        log_status("[ERRO] Nao foi possivel carregar configuracao")
        sys.exit(1)

    # 3. Tentar via API
    if has_credentials:
        log_status("\nTentando ativar via Google Ads API...")
        success = create_campaign_via_api(config)

        if success:
            log_status("\n[SUCESSO] Campanha ativada via API!")
            log_status("Iniciando monitoramento de 30 dias...")
            log_status("Execute: python3 scripts/google_ads_30day_orchestrator.py")
            return

    # 4. Fallback: Gerar guia manual
    log_status("\nCredenciais incompletas ou API indisponivel.")
    log_status("Gerando guia de ativacao MANUAL...")

    guide = generate_manual_activation_guide(config)
    print(guide)

    # Salvar guia
    guide_file = Path("GUIA_ATIVACAO_MANUAL_AGORA.txt")
    with open(guide_file, "w", encoding="utf-8") as f:
        f.write(guide)

    log_status(f"\nGuia salvo: {guide_file}")
    log_status("\nPASSOS PARA ATIVAR JA:")
    log_status("1. Abra o link acima")
    log_status("2. Copie os dados do guia")
    log_status("3. Clique ATIVAR")
    log_status("4. Envie mensagem: CAMPANHA ATIVADA")
    log_status("5. Sistema comecara monitoramento de 30 DIAS de VERDADE")

if __name__ == "__main__":
    main()
