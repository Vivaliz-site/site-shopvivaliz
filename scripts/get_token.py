import time
import hmac
import hashlib
import requests

PARTNER_ID = 1237032
PARTNER_KEY = "shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d"

CODE = "46705950714d6f455775517942704a53"
SHOP_ID = 227695582

PATH = "/api/v2/auth/token/get"

timestamp = int(time.time())

base = f"{PARTNER_ID}{PATH}{timestamp}"

sign = hmac.new(
    PARTNER_KEY.encode(),
    base.encode(),
    hashlib.sha256
).hexdigest()

url = (
    f"https://openplatform.sandbox.test-stable.shopee.sg{PATH}"
    f"?partner_id={PARTNER_ID}"
    f"&timestamp={timestamp}"
    f"&sign={sign}"
)

payload = {
    "code": CODE,
    "shop_id": SHOP_ID,
    "partner_id": PARTNER_ID
}

print("[*] Obtendo access_token e refresh_token...")
print(f"[*] URL: {url}\n")

r = requests.post(url, json=payload)

print(f"Status: {r.status_code}")
print(f"\nResposta:\n{r.text}\n")

if r.status_code == 200:
    try:
        data = r.json()
        print("[SUCESSO] Tokens obtidos!")
        print(f"Access Token: {data.get('access_token')}")
        print(f"Refresh Token: {data.get('refresh_token')}")
        print(f"Expira em: {data.get('expire_in')} segundos")
    except:
        print("[!] Nao foi possivel fazer parse da resposta JSON")
else:
    print("[ERRO] Falha ao obter tokens")
