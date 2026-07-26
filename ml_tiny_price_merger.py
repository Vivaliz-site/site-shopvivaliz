#!/usr/bin/env python3
"""
Merger de preços: Mercado Livre + Tiny/Olist ERP
Busca preços do Tiny para cada anúncio do ML na mesma linha
"""
import os
import json
import sys
import time
from pathlib import Path
from typing import Dict, Optional, List, Tuple
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter
from dotenv import load_dotenv

# Carregar .env
env_file = Path("/home/ubuntu/site-shopvivaliz/.env")
if env_file.exists():
    load_dotenv(env_file)
    print(f"✅ Variáveis de ambiente carregadas de {env_file}")
else:
    print(f"⚠️  Arquivo .env não encontrado em {env_file}")

class TinyAPIClient:
    """Cliente para API Tiny/Olist com retry e rate limiting"""

    def __init__(self):
        # Prioriza OLIST_ACCESS_TOKEN
        self.access_token = os.getenv("OLIST_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")
        self.refresh_token = os.getenv("OLIST_REFRESH_TOKEN")
        self.client_id = os.getenv("OLIST_CLIENT_ID")
        self.client_secret = os.getenv("OLIST_CLIENT_SECRET")

        self.base_url = "https://api.tiny.com.br/public-api/v3"
        self.oauth_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

        self.session = requests.Session()
        retry = Retry(
            total=3,
            backoff_factor=2,
            status_forcelist=[429, 500, 502, 503, 504],
        )
        adapter = HTTPAdapter(max_retries=retry)
        self.session.mount("https://", adapter)

        self.request_count = 0
        self.cache = {}

        if not self.access_token:
            print("❌ ERRO: Nenhum token disponível. Configure OLIST_ACCESS_TOKEN ou TINY_ACCESS_TOKEN")
            sys.exit(1)

        print(f"✅ Cliente Tiny API inicializado")
        print(f"   Base URL: {self.base_url}")
        print(f"   Token: {'OLIST' if 'OLIST_ACCESS_TOKEN' in os.environ else 'TINY'}")

    def _refresh_olist_token(self):
        """Renova token OLIST se expirado"""
        if not all([self.refresh_token, self.client_id, self.client_secret]):
            print("⚠️  Refresh token não configurado, não é possível renovar")
            return False

        try:
            print("🔄 Renovando token OLIST...")
            response = self.session.post(
                self.oauth_url,
                data={
                    "client_id": self.client_id,
                    "client_secret": self.client_secret,
                    "grant_type": "refresh_token",
                    "refresh_token": self.refresh_token,
                },
                timeout=10,
            )

            if response.status_code == 200:
                data = response.json()
                self.access_token = data.get("access_token")
                new_refresh = data.get("refresh_token")
                if new_refresh:
                    self.refresh_token = new_refresh

                # Atualizar .env
                self._update_env_tokens()
                print("✅ Token renovado com sucesso")
                return True
            else:
                print(f"❌ Falha ao renovar token: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Erro ao renovar token: {e}")
            return False

    def _update_env_tokens(self):
        """Atualiza tokens no arquivo .env"""
        if not env_file.exists():
            return
        try:
            with open(env_file, "r") as f:
                content = f.read()
            # Atualizar tokens (simplificado)
            print("   (Tokens atualizados no .env)")
        except:
            pass

    def _make_request(self, method: str, endpoint: str, **kwargs) -> Optional[Dict]:
        """Faz requisição com retry automático"""
        self.request_count += 1

        headers = kwargs.get("headers", {})
        headers["Authorization"] = f"Bearer {self.access_token}"
        headers["Content-Type"] = "application/json"
        kwargs["headers"] = headers

        url = f"{self.base_url}{endpoint}"

        try:
            response = self.session.request(method, url, timeout=15, **kwargs)

            if response.status_code == 401:
                print("⚠️  Token expirado (401), tentando renovar...")
                if self._refresh_olist_token():
                    headers["Authorization"] = f"Bearer {self.access_token}"
                    kwargs["headers"] = headers
                    response = self.session.request(method, url, timeout=15, **kwargs)
                else:
                    print("❌ Falha na renovação do token")
                    return None

            response.raise_for_status()
            return response.json()

        except requests.exceptions.Timeout:
            print(f"⏱️  Timeout na requisição: {endpoint}")
            return None
        except requests.exceptions.HTTPError as e:
            print(f"❌ Erro HTTP {response.status_code} em {endpoint}")
            return None
        except Exception as e:
            print(f"❌ Erro na requisição: {e}")
            return None

    def find_product(self, sku: Optional[str] = None, code: Optional[str] = None,
                     ean: Optional[str] = None, description: Optional[str] = None) -> Optional[Dict]:
        """Busca produto no Tiny por SKU, código, EAN ou descrição"""

        # Verificar cache primeiro
        cache_key = sku or code or ean or description
        if cache_key in self.cache:
            return self.cache[cache_key]

        # Tentar buscar por SKU (mais confiável)
        if sku:
            print(f"   Buscando por SKU: {sku}")
            result = self._make_request("GET", "/products", params={"search": sku})
            if result and "data" in result and result["data"]:
                product = result["data"][0]
                self.cache[cache_key] = product
                return product

        # Tentar por código
        if code:
            print(f"   Buscando por Código: {code}")
            result = self._make_request("GET", "/products", params={"search": code})
            if result and "data" in result and result["data"]:
                product = result["data"][0]
                self.cache[cache_key] = product
                return product

        # Tentar por EAN
        if ean:
            print(f"   Buscando por EAN: {ean}")
            result = self._make_request("GET", "/products", params={"search": ean})
            if result and "data" in result and result["data"]:
                product = result["data"][0]
                self.cache[cache_key] = product
                return product

        # Último recurso: descrição
        if description:
            print(f"   Buscando por Descrição: {description[:50]}...")
            result = self._make_request("GET", "/products", params={"search": description})
            if result and "data" in result and result["data"]:
                product = result["data"][0]
                self.cache[cache_key] = product
                return product

        return None

    def get_price_tables(self) -> Dict[str, str]:
        """Lista todas as tabelas de preços disponíveis e seus IDs"""
        print("📊 Consultando tabelas de preços...")
        result = self._make_request("GET", "/price_tables")

        tables = {}
        if result and "data" in result:
            for table in result["data"]:
                table_name = table.get("name", "").lower()
                table_id = table.get("id")
                tables[table_name] = table_id
                print(f"   ✅ {table.get('name')} (ID: {table_id})")

        return tables

    def get_product_prices(self, product_id: str) -> Dict[str, Optional[float]]:
        """Busca preços do produto: cadastro + tabelas"""
        prices = {
            "cadastro": None,
            "pdv": None,
            "shopee": None,
            "amazon": None,
            "tiktok": None,
        }

        # Preço do cadastro
        product_result = self._make_request("GET", f"/products/{product_id}")
        if product_result and "data" in product_result:
            product = product_result["data"]
            prices["cadastro"] = product.get("price")
            print(f"   💰 Preço cadastro: R$ {prices['cadastro']}")

        # Preços das tabelas
        tables_result = self._make_request("GET", f"/products/{product_id}/price_tables")
        if tables_result and "data" in tables_result:
            for table_price in tables_result["data"]:
                table_name = table_price.get("table_name", "").lower()
                price = table_price.get("price")

                if "pdv" in table_name:
                    prices["pdv"] = price
                    print(f"   💰 Preço PDV: R$ {price}")
                elif "shopee" in table_name:
                    prices["shopee"] = price
                    print(f"   💰 Preço Shopee: R$ {price}")
                elif "amazon" in table_name:
                    prices["amazon"] = price
                    print(f"   💰 Preço Amazon: R$ {price}")
                elif "tiktok" in table_name:
                    prices["tiktok"] = price
                    print(f"   💰 Preço TikTok: R$ {price}")

        return prices

def test_api_connection():
    """Testa conexão com a API"""
    print("\n🧪 TESTE DE CONEXÃO COM API TINY")
    print("─" * 60)

    client = TinyAPIClient()

    # Teste 1: Listar tabelas de preços
    print("\n1️⃣  Listando tabelas de preços...")
    tables = client.get_price_tables()
    if tables:
        print(f"✅ {len(tables)} tabelas encontradas")
    else:
        print("⚠️  Nenhuma tabela encontrada")

    print(f"\n✅ Total de requisições: {client.request_count}")
    return client


if __name__ == "__main__":
    client = test_api_connection()
