import time
import hmac
import hashlib
import requests
import json

PARTNER_ID = 1237032
PARTNER_KEY = "shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d"
ACCESS_TOKEN = "535a586d674844627874525179787554"
SHOP_ID = 227695582

BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg"

path = "/api/v2/shop/get_shop_info"
timestamp = int(time.time())

# Tenta diferentes ordens de assinatura
bases_to_try = [
    f"{PARTNER_ID}{path}{SHOP_ID}{timestamp}",  # Ordem 1
    f"{PARTNER_ID}{path}{timestamp}{SHOP_ID}",  # Ordem 2
    f"{PARTNER_ID}{path}{timestamp}",            # Sem shop_id
]

print("Testando diferentes assinaturas...\n")

for i, base in enumerate(bases_to_try):
    sign = hmac.new(
        PARTNER_KEY.encode(),
        base.encode(),
        hashlib.sha256
    ).hexdigest()

    url = (
        f"{BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
        f"&access_token={ACCESS_TOKEN}"
        f"&shop_id={SHOP_ID}"
    )

    print(f"Tentativa {i+1}: base = '{base}'")
    response = requests.get(url)
    print(f"  Status: {response.status_code}")

    if response.status_code == 200:
        data = response.json()
        if data.get("data"):
            print(f"  [SUCESSO!]\n")
            print("=" * 70)
            print("[SUCESSO] CONEXAO ESTABELECIDA COM SUCESSO!")
            print("=" * 70)
            print("\nInformacoes da Loja:")
            print(json.dumps(data.get("data"), indent=2))
            exit(0)
        else:
            print(f"  Error: {data.get('error')}")
    else:
        print(f"  Error: {response.json().get('error')}")
    print()

print("[!] Nenhuma combinacao funcionou")
exit(1)

url = (
    f"{BASE_URL}{path}"
    f"?partner_id={PARTNER_ID}"
    f"&timestamp={timestamp}"
    f"&sign={sign}"
    f"&access_token={ACCESS_TOKEN}"
    f"&shop_id={SHOP_ID}"
)

print("=" * 70)
print("TESTE FINAL - CONEXAO COM API DO SHOPEE")
print("=" * 70)
print(f"\nURL: {url}\n")

response = requests.get(url)

print(f"Status: {response.status_code}")
print(f"\nResposta:")
print(response.text)
print()

if response.status_code == 200:
    try:
        data = response.json()
        if data.get("data"):
            print("=" * 70)
            print("[SUCESSO] CONEXAO ESTABELECIDA COM SUCESSO!")
            print("=" * 70)
            print("\nInformacoes da Loja:")
            print(json.dumps(data.get("data"), indent=2))
        elif data.get("error") == "error_param":
            print("[!] Erro de parametros - shop_id pode estar incorreto")
        else:
            print(f"[!] Resposta: {data}")
    except:
        print("[!] Nao foi possivel fazer parse JSON")
else:
    print(f"[ERRO] Status {response.status_code}")

print()
