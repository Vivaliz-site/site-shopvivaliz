import os
import requests
from pathlib import Path
from dotenv import load_dotenv

for f in [Path(r"C:\site-shopvivaliz\.env.local"), Path(r"C:\site-shopvivaliz\.env")]:
    if f.exists():
        load_dotenv(f)
        break

token = os.getenv("TINY_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")

headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}

url = "https://api.tiny.com.br/public-api/v3/produtos?limit=100&offset=0"
resp = requests.get(url, headers=headers, timeout=30)

print("Primeiros 15 produtos do Tiny:")
print("-"*120)

for prod in resp.json().get("itens", [])[:15]:
    id_p = prod.get("id")
    sku = prod.get("sku") or "N/A"
    desc = prod.get("descricao")[:70] if prod.get("descricao") else "N/A"

    print(f"ID {id_p:10d} | SKU {str(sku):15s} | {desc}")
