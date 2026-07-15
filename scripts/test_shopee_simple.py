import time
import hmac
import hashlib
import requests

PARTNER_ID = 1237032
PARTNER_KEY = "shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d"
ACCESS_TOKEN = "535a586d674844627874525179787554"
SHOP_ID = 227695582

BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg"

def test_with_post():
    """Testa usando POST request"""
    print("\n=== TESTE COM POST REQUEST ===\n")

    path = "/api/v2/shop/get_shop_info"
    timestamp = int(time.time())

    # A assinatura NÃO deve incluir shop_id no path
    base = f"{PARTNER_ID}{path}{timestamp}"
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
    )

    payload = {
        "shop_id": SHOP_ID
    }

    print(f"URL: {url}")
    print(f"Payload: {payload}\n")

    try:
        response = requests.post(url, json=payload)
        print(f"Status: {response.status_code}")
        print(f"Resposta:\n{response.text}\n")

        if response.status_code == 200:
            print("[SUCESSO] API esta respondendo corretamente!")
            try:
                data = response.json()
                if data.get("data"):
                    print("\nDados da loja:")
                    for key, value in data.get("data", {}).items():
                        print(f"  {key}: {value}")
            except:
                pass

    except Exception as e:
        print(f"[ERRO] {e}")

def test_basic_connection():
    """Testa conexao basica sem shop_id"""
    print("\n=== TESTE BASICO (SEM SHOP_ID) ===\n")

    path = "/api/v2/shop/get_shop_info"
    timestamp = int(time.time())

    base = f"{PARTNER_ID}{path}{timestamp}"
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
    )

    print(f"URL: {url}\n")

    try:
        response = requests.get(url)
        print(f"Status: {response.status_code}")
        print(f"Resposta:\n{response.text}\n")

    except Exception as e:
        print(f"[ERRO] {e}")

if __name__ == "__main__":
    print("=" * 60)
    print("TESTE DE CONEXAO COM API DO SHOPEE (SIMPLES)")
    print("=" * 60)

    print(f"\nConfiguracao:")
    print(f"  Partner ID: {PARTNER_ID}")
    print(f"  Shop ID: {SHOP_ID}")
    print(f"  Access Token: {ACCESS_TOKEN[:20]}...")

    test_basic_connection()
    test_with_post()

    print("=" * 60)
