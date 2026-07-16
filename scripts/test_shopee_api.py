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

def sign_request(path, timestamp, shop_id=None):
    """Gera a assinatura para a requisição"""
    if shop_id:
        base = f"{PARTNER_ID}{path}{timestamp}{shop_id}"
    else:
        base = f"{PARTNER_ID}{path}{timestamp}"
    return hmac.new(
        PARTNER_KEY.encode(),
        base.encode(),
        hashlib.sha256
    ).hexdigest()

def make_request(path, method="GET", data=None):
    """Faz uma requisição à API do Shopee"""
    timestamp = int(time.time())
    sign = sign_request(path, timestamp, SHOP_ID)

    url = (
        f"{BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
        f"&access_token={ACCESS_TOKEN}"
        f"&shop_id={SHOP_ID}"
    )

    print(f"\n[*] Testando: {path}")
    print(f"    URL: {url}")

    try:
        if method == "GET":
            response = requests.get(url)
        elif method == "POST":
            response = requests.post(url, json=data)

        print(f"    Status: {response.status_code}")

        if response.status_code == 200:
            try:
                result = response.json()
                print(f"    [OK] Resposta recebida")
                return True, result
            except:
                print(f"    [!] Nao foi possivel fazer parse JSON")
                return False, response.text
        else:
            print(f"    [ERRO] Status {response.status_code}")
            print(f"    {response.text}")
            return False, response.text

    except Exception as e:
        print(f"    [ERRO] {e}")
        return False, str(e)

def main():
    print("=" * 60)
    print("TESTE DE CONEXAO COM API DO SHOPEE")
    print("=" * 60)

    print(f"\nConfiguracao:")
    print(f"  Partner ID: {PARTNER_ID}")
    print(f"  Shop ID: {SHOP_ID}")
    print(f"  Access Token: {ACCESS_TOKEN[:20]}...")
    print(f"  Base URL: {BASE_URL}\n")

    tests = [
        ("/api/v2/shop/get_shop_info", "GET"),
        ("/api/v2/product/get_categories", "GET"),
        ("/api/v2/product/search_product", "GET"),
    ]

    passed = 0
    failed = 0

    for path, method in tests:
        success, response = make_request(path, method)

        if success:
            passed += 1
            if isinstance(response, dict):
                print(f"    Dados: {json.dumps(response, indent=2)[:200]}...")
        else:
            failed += 1

    print("\n" + "=" * 60)
    print(f"RESULTADO: {passed} OK | {failed} ERRO")
    print("=" * 60)

    if failed == 0:
        print("\n[SUCESSO] API do Shopee esta funcionando corretamente!")
    else:
        print("\n[AVISO] Alguns testes falharam - verifique os erros acima")

if __name__ == "__main__":
    main()
