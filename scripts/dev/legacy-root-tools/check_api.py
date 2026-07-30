#!/usr/bin/env python3
import os
import requests
from pathlib import Path
from dotenv import load_dotenv

env_file = Path(r"C:\site-shopvivaliz\.env.local")
load_dotenv(env_file)

token = os.getenv("OLIST_ACCESS_TOKEN")
headers = {"Authorization": "Bearer " + token}

url = "https://api.tiny.com.br/public-api/v3/produtos?limit=10"

resp = requests.get(url, headers=headers, timeout=20)

print("Status:", resp.status_code)
print("Headers:", dict(resp.headers))
print("Texto:", resp.text[:300])
