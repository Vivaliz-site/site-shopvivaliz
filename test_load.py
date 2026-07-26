#!/usr/bin/env python3
import os
import requests
from pathlib import Path
from dotenv import load_dotenv
import json

env_file = Path(r"C:\site-shopvivaliz\.env.local")
load_dotenv(env_file)

token = os.getenv("OLIST_ACCESS_TOKEN")
headers = {"Authorization": "Bearer " + token}

print("Teste de carregamento de produtos...")

# Requisição simples
resp = requests.get(
    "https://api.tiny.com.br/public-api/v3/produtos?limit=10&offset=0",
    headers=headers,
    timeout=20
)

print("Status:", resp.status_code)
print("Tamanho:", len(resp.text), "bytes")

if resp.status_code == 200:
    try:
        data = resp.json()
        print("Chaves:", list(data.keys()))
        if "itens" in data:
            print("Itens:", len(data.get("itens", [])))
            if data.get("itens"):
                print("Primeiro item:", data["itens"][0].get("descricao", "")[:50])
    except Exception as e:
        print("Erro ao parsear JSON:", e)
        print("Texto:", resp.text[:300])
else:
    print("Erro:", resp.text[:300])
