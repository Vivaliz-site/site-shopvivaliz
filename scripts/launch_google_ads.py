#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
LANCADOR DE CAMPANHA GOOGLE ADS - ShopVivaliz
Produto Campeao: Rodizios 35mm Gel
Orcamento: R$ 15.00/dia
"""

import json
from datetime import datetime

# ===== CONFIG =====
CAMPAIGN = {
    "name": "Rodizios-Search-ShopVivaliz-2026-07",
    "budget_daily": 15.00,
    "status": "PAUSED",  # Iniciar pausada para review
    "keywords": 8,
    "headlines": 12,
    "descriptions": 6,
    "negative_keywords": 8,
    "locations": ["SP", "MG", "PR"],
    "cpc_target": "R$ 1.50-2.50"
}

print("\n" + "="*70)
print("GOOGLE ADS CAMPAIGN LAUNCHER - ShopVivaliz")
print("="*70)
print("\nCampanha: " + CAMPAIGN["name"])
print("Orcamento: R$ " + str(CAMPAIGN["budget_daily"]) + "/dia")
print("Status: " + CAMPAIGN["status"] + " (revisar antes de ativar)")
print("Palavras-chave: " + str(CAMPAIGN["keywords"]))
print("Headlines: " + str(CAMPAIGN["headlines"]))
print("Negativas: " + str(CAMPAIGN["negative_keywords"]))
print("\n" + "="*70)

# Salvar config
config_file = "scripts/google_ads_launch_config.json"
with open(config_file, "w", encoding="utf-8") as f:
    json.dump(CAMPAIGN, f, ensure_ascii=False, indent=2)

print("\nArquivo gerado: " + config_file)
print("\nCOMO USAR:")
print("\n1. NAVEGAR PARA GOOGLE ADS:")
print("   - URL: https://ads.google.com")
print("   - Account: 5104079137")
print("\n2. CRIAR CAMPANHA MANUALMENTE:")
print("   - Tipo: Pesquisa (Search)")
print("   - Nome: " + CAMPAIGN["name"])
print("   - Orcamento: R$ " + str(CAMPAIGN["budget_daily"]) + "/dia")
print("\n3. ADICIONAR KEYWORDS:")
print("   - ALTA PRIORIDADE:")
print("     * rodizios gel soprano 35mm (PHRASE)")
print("     * rodizio giratório com freio (PHRASE)")
print("     * kit rodizios 35mm freio (PHRASE)")
print("   ")
print("   - MEDIA PRIORIDADE:")
print("     * rodizios para móvel (PHRASE)")
print("     * rodizio gel silicone (PHRASE)")
print("     * rodizio giratório (BROAD)")
print("   ")
print("   - BAIXA PRIORIDADE:")
print("     * rodizios (BROAD)")
print("     * ferragens para móvel (BROAD)")
print("\n4. ADICIONAR NEGATIVAS:")
print("     * rodizio barato, gratis, free, download, usado")
print("     * rodizio segunda mão, emprego, curso")
print("\n5. CRIAR ANUNCIOS (RSA - Responsive Search Ads):")
print("   Usar os 12 headlines e 6 descriptions do arquivo")
print("   scripts/google_ads_campaign_config.json")
print("\n6. REVISAR E ATIVAR:")
print("   - CPC recomendado: R$ 1.50-2.50 por keyword")
print("   - Ativar quando pronto")
print("\n" + "="*70)
print("ARQUIVO REFERENCIA: " + config_file + "\n")

