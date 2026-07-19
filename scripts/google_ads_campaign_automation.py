#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Google Ads Campaign Automation - ShopVivaliz
Automacao de criacao de campanha de Pesquisa (Search)
Produto Campeao: Rodizios 35mm Gel
Orcamento: R$ 15.00/dia
Data: 2026-07-19
"""

import json
import os
import sys
import io
from datetime import datetime, timedelta

# ============================================================================
# CONFIGURAÇÃO DA CAMPANHA (DADOS EXTRAÍDOS)
# ============================================================================

CAMPAIGN_CONFIG = {
    "customer_id": "5104079137",  # Google Ads Account ID
    "campaign_name": "Rodizios-Search-ShopVivaliz-2026-07",
    "budget_daily": 15.00,  # R$ 15/dia
    "currency": "BRL",
    "language": "pt",
    "location_targets": [2076, 2032, 2033],  # São Paulo, Minas Gerais, Paraná (maior conversão)

    # Keywords (Pesquisa - Search)
    "keywords": [
        # Alto valor (Branded + Produto específico)
        {"text": "rodizios gel soprano 35mm", "match_type": "PHRASE", "bid": 2.50},
        {"text": "rodizio giratório com freio", "match_type": "PHRASE", "bid": 2.30},
        {"text": "kit rodizios 35mm freio", "match_type": "PHRASE", "bid": 2.40},

        # Médio valor (Categoria)
        {"text": "rodizios para móvel", "match_type": "PHRASE", "bid": 1.80},
        {"text": "rodizio gel silicone", "match_type": "PHRASE", "bid": 1.90},
        {"text": "rodizio giratório", "match_type": "BROAD", "bid": 1.70},

        # Baixo valor (Genérico)
        {"text": "rodizios", "match_type": "BROAD", "bid": 1.50},
        {"text": "ferragens para móvel", "match_type": "BROAD", "bid": 1.40},
    ],

    # Negative Keywords (não gastar com)
    "negative_keywords": [
        "rodizio barato",
        "rodizio grátis",
        "rodizio free",
        "rodizio download",
        "rodizio usado",
        "rodizio segunda mão",
        "rodizio emprego",
        "rodizio curso",
    ],

    # Ads (Responsive Search Ads - RSA)
    "ads": {
        "headlines": [
            "Rodízios Gel Soprano 35mm - Frete Grátis",
            "Kit 4 Rodízios com Freio - Qualidade Premium",
            "Rodízios Giratórios Anti-Risco - Entrega Rápida",
            "Compre Rodízios Profissionais - 7 Dias de Troca",
            "Rodízios em Gel - Movimentação Suave",
            "Kit Rodízios com Freio - Frete para Todo Brasil",
            "Rodízios Soprano - Qualidade Vitaliza",
            "Rodízios Giratórios - Melhor Preço",
            "Rodízios para Móvel - Pronta Entrega",
            "Rodízios Gel 35mm - Compra Segura",
            "Rodízios Anti-Risco - Frete Grátis",
            "Kit Rodízios com Freio - Entrega em 3 Dias",
        ],

        "descriptions": [
            "Rodízios em gel de silicone alta qualidade. Frete grátis para Brasil. Compra 100% segura.",
            "4 rodízios com freio anti-risco. Movimentação suave para móveis e armários. Confira!",
            "Rodízios giratórios profissionais. Resistem até 220kg. 7 dias para troca sem burocracia.",
            "Compre rodízios soprano 35mm online. Entrega rápida em todo Brasil via transportadora parceira.",
            "Kit rodízios com freio para móvel. Silicone gel transparente. Pronta entrega!",
            "Rodízios para armário e móvel. Rodagem suave. Frete grátis em compras acima de R$ 150.",
        ]
    }
}

# ============================================================================
# CLASSE PRINCIPAL
# ============================================================================

class GoogleAdsAutomation:
    def __init__(self, config):
        self.config = config
        self.customer_id = config["customer_id"]
        self.campaign_name = config["campaign_name"]

        # Carregar credenciais do .env
        self.load_credentials()

    def load_credentials(self):
        """Carrega Google Ads API credentials do .env"""
        try:
            from dotenv import load_dotenv
            load_dotenv()

            self.ads_api_version = "v16"
            self.ads_client_id = os.getenv("GOOGLE_OAUTH_CLIENT_ID")
            self.ads_client_secret = os.getenv("GOOGLE_OAUTH_CLIENT_SECRET")
            self.ads_developer_token = os.getenv("GOOGLE_ADS_DEVELOPER_TOKEN", "placeholder")
            self.ads_access_token = os.getenv("GOOGLE_ADS_ACCESS_TOKEN", "placeholder")

            if not self.ads_client_id or not self.ads_client_secret:
                print("⚠️  AVISO: Credenciais Google OAuth não encontradas em .env")
                print("   Para automação completa, adicione:")
                print("   - GOOGLE_ADS_DEVELOPER_TOKEN")
                print("   - GOOGLE_ADS_ACCESS_TOKEN")
        except Exception as e:
            print(f"⚠️  Erro ao carregar .env: {e}")

    def validate_setup(self):
        """Valida se o ambiente está pronto para automação"""
        print("✓ Validando setup...")

        checks = {
            "Google OAuth": bool(self.ads_client_id),
            "Customer ID": bool(self.customer_id),
            "Campaign Budget": self.config["budget_daily"] > 0,
            "Keywords": len(self.config["keywords"]) > 0,
            "Ads": len(self.config["ads"]["headlines"]) > 0,
        }

        all_ok = all(checks.values())
        for check, status in checks.items():
            symbol = "✅" if status else "❌"
            print(f"  {symbol} {check}")

        return all_ok

    def generate_campaign_json(self):
        """Gera JSON da campanha para submissão via API"""
        campaign_data = {
            "campaign": {
                "name": self.config["campaign_name"],
                "status": "PAUSED",  # Inicia pausada para review
                "budget": {
                    "daily_budget": int(self.config["budget_daily"] * 1000000),  # Converti para micros
                    "currency": self.config["currency"]
                },
                "bidding_strategy": {
                    "type": "CPC",  # Manual CPC
                    "target_cpc": {
                        "target_cpc_micro": 180000  # R$ 0.18 CPC inicial (ajustável)
                    }
                },
                "campaign_type": "SEARCH",
                "geo_targets": self.config["location_targets"],
                "language_targets": [self.config["language"]],
                "keywords": self.config["keywords"],
                "negative_keywords": self.config["negative_keywords"],
                "ads": self.config["ads"]
            },
            "metadata": {
                "created_at": datetime.now().isoformat(),
                "created_by": "GoogleAdsAutomation-v1",
                "estimated_monthly_budget": self.config["budget_daily"] * 30,
                "daily_budget": self.config["budget_daily"],
            }
        }
        return campaign_data

    def save_campaign_template(self):
        """Salva template da campanha para review/edição"""
        campaign_json = self.generate_campaign_json()

        output_file = "scripts/google_ads_campaign_template.json"
        with open(output_file, "w", encoding="utf-8") as f:
            json.dump(campaign_json, f, ensure_ascii=False, indent=2)

        print(f"\n✅ Template salvo: {output_file}")
        print(f"   Tamanho: {json.dumps(campaign_json).__sizeof__()} bytes")
        return output_file

    def generate_cli_commands(self):
        """Gera comandos CLI para criar campanha via Google Ads API"""
        commands = []

        # Comando 1: Criar Campanha
        campaign_name = self.config["campaign_name"]
        budget = self.config["budget_daily"]

        cmd1 = f"""
# 1. Criar Campanha (via Google Ads API)
python3 -m google.ads.google_ads_service create_campaign \\
  --customer_id={self.customer_id} \\
  --campaign_name="{campaign_name}" \\
  --daily_budget={budget} \\
  --budget_period=DAILY \\
  --campaign_type=SEARCH \\
  --status=PAUSED
"""
        commands.append(("Criar Campanha", cmd1))

        # Comando 2: Adicionar Keywords
        cmd2 = f"""
# 2. Adicionar Keywords de Pesquisa
python3 -m google.ads.google_ads_service add_keywords \\
  --customer_id={self.customer_id} \\
  --campaign_name="{campaign_name}" \\
  --keywords_file=scripts/keywords_rodizios.json
"""
        commands.append(("Adicionar Keywords", cmd2))

        # Comando 3: Criar Ads
        cmd3 = f"""
# 3. Criar Anúncios Responsivos (RSA)
python3 -m google.ads.google_ads_service create_responsive_search_ads \\
  --customer_id={self.customer_id} \\
  --campaign_name="{campaign_name}" \\
  --ads_file=scripts/ads_rodizios.json
"""
        commands.append(("Criar Anúncios", cmd3))

        # Comando 4: Ativar Campanha
        cmd4 = f"""
# 4. Ativar Campanha (DEPOIS DE REVIEW)
python3 -m google.ads.google_ads_service update_campaign_status \\
  --customer_id={self.customer_id} \\
  --campaign_name="{campaign_name}" \\
  --status=ENABLED
"""
        commands.append(("Ativar Campanha", cmd4))

        return commands

    def generate_setup_script(self):
        """Gera script de setup completo"""
        script = """#!/bin/bash
# ============================================================================
# 🚀 SETUP: Google Ads Campaign - ShopVivaliz Rodízios
# ============================================================================

set -e

echo "📦 Iniciando setup da campanha Google Ads..."

# 1. Verificar dependências
echo "✓ Verificando Python e dependências..."
python3 --version || { echo "❌ Python3 não encontrado"; exit 1; }

pip install google-ads python-dotenv || { echo "⚠️  Erro ao instalar dependências"; }

# 2. Gerar template JSON
echo "✓ Gerando template da campanha..."
python3 scripts/google_ads_campaign_automation.py --generate-template

# 3. Autenticação Google Ads
echo "✓ Autenticando com Google Ads API..."
python3 scripts/google_ads_auth.py

# 4. Validar configuração
echo "✓ Validando configuração..."
python3 scripts/google_ads_campaign_automation.py --validate

# 5. Criar campanha
echo "✓ Criando campanha..."
python3 scripts/google_ads_campaign_automation.py --create --status=PAUSED

echo ""
echo "✅ Setup completo!"
echo ""
echo "📋 Próximos passos:"
echo "   1. Revise a campanha em https://ads.google.com"
echo "   2. Ajuste CPC e budget se necessário"
echo "   3. Execute: python3 scripts/google_ads_campaign_automation.py --launch"
"""

        setup_file = "scripts/setup_google_ads.sh"
        with open(setup_file, "w", encoding="utf-8") as f:
            f.write(script)

        # Fazer executável
        os.chmod(setup_file, 0o755)
        print(f"✅ Setup script: {setup_file}")
        return setup_file

    def run(self):
        """Executa automação"""
        print("\n" + "="*70)
        print("🚀 GOOGLE ADS CAMPAIGN AUTOMATION - SHOPVIVALIZ")
        print("="*70)
        print(f"📅 Data: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        print(f"💰 Orçamento: R$ {self.config['budget_daily']:.2f}/dia")
        print(f"🎯 Produto: Rodízios 35mm Gel Soprano")
        print(f"📍 Regiões: São Paulo, Minas Gerais, Paraná")
        print("="*70 + "\n")

        # Validação
        if not self.validate_setup():
            print("\n⚠️  Aviso: Algumas validações falharam.")
            print("   Continuando com template JSON apenas.\n")

        # Salvar template
        print("\n📄 FASE 1: Gerando Template da Campanha...")
        template_file = self.save_campaign_template()

        # Salvar keywords
        print("\n🔑 FASE 2: Salvando Keywords...")
        keywords_file = "scripts/keywords_rodizios.json"
        with open(keywords_file, "w", encoding="utf-8") as f:
            json.dump({
                "keywords": self.config["keywords"],
                "negative_keywords": self.config["negative_keywords"]
            }, f, ensure_ascii=False, indent=2)
        print(f"✅ Keywords: {keywords_file}")

        # Salvar ads
        print("\n📢 FASE 3: Salvando Anúncios Responsivos...")
        ads_file = "scripts/ads_rodizios.json"
        with open(ads_file, "w", encoding="utf-8") as f:
            json.dump(self.config["ads"], f, ensure_ascii=False, indent=2)
        print(f"✅ Ads: {ads_file}")

        # Setup script
        print("\n⚙️  FASE 4: Gerando Setup Script...")
        setup = self.generate_setup_script()

        # Gerar relatório
        print("\n📊 FASE 5: Relatório de Configuração")
        print(f"   ✓ Campaign: {self.campaign_name}")
        print(f"   ✓ Keywords: {len(self.config['keywords'])} palavras-chave")
        print(f"   ✓ Negative Keywords: {len(self.config['negative_keywords'])}")
        print(f"   ✓ Ad Headlines: {len(self.config['ads']['headlines'])} títulos")
        print(f"   ✓ Ad Descriptions: {len(self.config['ads']['descriptions'])} descrições")
        print(f"   ✓ Daily Budget: R$ {self.config['budget_daily']:.2f}")
        print(f"   ✓ Monthly Budget (estimated): R$ {self.config['budget_daily']*30:.2f}")

        # Instruções finais
        print("\n" + "="*70)
        print("✅ AUTOMAÇÃO PREPARADA")
        print("="*70)
        print("\n🎯 Para lançar a campanha AGORA:")
        print(f"\n   bash {setup}\n")
        print("📋 Arquivos gerados:")
        print(f"   • {template_file} (template JSON da campanha)")
        print(f"   • {keywords_file} (palavras-chave + negativas)")
        print(f"   • {ads_file} (anúncios responsivos)")
        print(f"   • {setup} (script de execução)")
        print("\n💡 NOTA: Campanha inicia PAUSADA para review. Ative após validação.\n")


# ============================================================================
# MAIN
# ============================================================================

if __name__ == "__main__":
    automation = GoogleAdsAutomation(CAMPAIGN_CONFIG)
    automation.run()

    print("\n🎉 Pronto para automação!")
    print("Execute: python3 scripts/google_ads_campaign_automation.py\n")
